<?php

declare(strict_types=1);

namespace Survos\WorkflowExample\Tests;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Survos\WorkflowExample\ImageProcessing\Image;
use Survos\WorkflowExample\ImageProcessing\ImageKernel;
use Survos\WorkflowExample\ImageProcessing\ImageStore;
use Survos\WorkflowExample\ImageProcessing\ImageWorkflow as WF;
use Survos\WorkflowExample\RunQueue;
use Survos\WorkflowExtrasBundle\Service\NextTransitionRunner;
use Survos\WorkflowMessengerBundle\Message\SubjectReference;
use Survos\WorkflowMessengerBundle\Service\TransitionDispatcher;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\Stamp\HandledStamp;
use Symfony\Component\Messenger\Stamp\ReceivedStamp;
use Symfony\Component\Messenger\Transport\InMemory\InMemoryTransport;
use Symfony\Component\Workflow\Workflow;
use Symfony\Contracts\HttpClient\HttpClientInterface;

final class ImageWorkflowTest extends TestCase
{
    private ImageKernel $kernel;
    private string $directory;
    private ImageStore $store;
    private SubjectReference $reference;
    private array $calls = [];
    private ?string $failPath = null;

    protected function setUp(): void
    {
        $this->directory = sys_get_temp_dir().'/workflow-image-'.bin2hex(random_bytes(6));
        $this->kernel = new ImageKernel($this->directory);
        $this->kernel->boot();
        $container = $this->kernel->getContainer();
        $container->set(HttpClientInterface::class, new MockHttpClient(function (string $method, string $url, array $options): MockResponse {
            $path = parse_url($url, PHP_URL_PATH);
            $this->calls[] = [$path, $options];
            if ($this->failPath === $path) {
                return new MockResponse('Temporary service failure', ['http_code' => 503]);
            }
            if (in_array($path, ['/thumbnail', '/description'], true)) {
                self::assertSame('source-image-bytes', $options['body']);
            }

            return new MockResponse(match ($path) {
                '/source' => 'source-image-bytes',
                '/thumbnail' => json_encode(['url' => 'https://example.invalid/thumb.jpg'], JSON_THROW_ON_ERROR),
                '/description' => json_encode(['description' => 'A museum photograph'], JSON_THROW_ON_ERROR),
                '/webhook' => '',
                default => throw new \LogicException('Unexpected example integration: '.$url),
            });
        }));
        $this->store = $container->get(ImageStore::class);
        $this->reference = new SubjectReference('image', 'run-1');
        $this->store->save($this->reference, new Image($this->reference->id));
    }

    protected function tearDown(): void
    {
        $this->kernel->shutdown();
        (new Filesystem())->remove($this->directory);
    }

    public function testNativeAttributesAndAutomaticPlaceNextCompleteTheJoin(): void
    {
        $workflow = $this->workflow();
        self::assertInstanceOf(Workflow::class, $workflow);
        self::assertSame([WF::THUMBNAIL], $workflow->getMetadataStore()->getPlaceMetadata(WF::THUMBNAIL_PENDING)['next']);
        $this->runner()->start($workflow, $this->store->load($this->reference));
        self::assertSame([], $this->calls, 'Enqueueing must not run the integrations.');
        $this->work('image_download');
        $this->assertPlaces([WF::THUMBNAIL_PENDING, WF::DESCRIPTION_PENDING]);
        self::assertFalse($workflow->can($this->store->load($this->reference), WF::COMPLETE));
        self::assertCount(1, $this->queue('image_thumbnail')->getSent());
        self::assertCount(0, $this->queue('image_description')->getSent(), 'next selects one branch, not fan-out.');

        $this->work('image_thumbnail');
        $this->assertPlaces([WF::THUMBNAIL_DONE, WF::DESCRIPTION_PENDING]);
        self::assertCount(0, $this->queue('image_webhook')->getSent());
        $this->work('image_description');
        $this->assertPlaces([WF::THUMBNAIL_DONE, WF::DESCRIPTION_DONE]);
        self::assertCount(1, $this->queue('image_webhook')->getSent());
        $this->work('image_webhook');
        $this->assertPlaces([WF::COMPLETED]);
        self::assertSame(['/source', '/thumbnail', '/description', '/webhook'], array_column($this->calls, 0));
        $this->assertWebhookPayload();
    }

    public static function branchOrders(): iterable
    {
        yield 'thumbnail first' => [[WF::THUMBNAIL, WF::DESCRIBE]];
        yield 'description first' => [[WF::DESCRIBE, WF::THUMBNAIL]];
    }

    public function testSyncCascadeUsesTheSameMultiPlaceJoin(): void
    {
        $this->runner()->start($this->workflow(), $this->store->load($this->reference), ['cascade' => 'sync']);
        $this->assertPlaces([WF::COMPLETED]);
        foreach (ImageKernel::TRANSPORTS as $name) {
            self::assertCount(0, $this->queue($name)->getSent());
        }
        $this->assertWebhookPayload();
    }

    #[DataProvider('branchOrders')]
    public function testExplicitlyQueuedBranchesCanFinishInEitherOrder(array $order): void
    {
        $this->dispatch(WF::DOWNLOAD, ['cascade' => 'none']);
        $this->work('image_download');

        $early = $this->dispatch(WF::COMPLETE);
        $this->work('image_webhook');
        self::assertSame('blocked', $this->redeliver($early, 'image_webhook'));
        self::assertSame(['/source'], array_column($this->calls, 0));

        $routes = [WF::THUMBNAIL => 'image_thumbnail', WF::DESCRIBE => 'image_description'];
        $messages = [];
        foreach ($order as $transition) {
            // An external producer may enqueue both branches. Disable auto chaining
            // here so the explicit producer is the only source of branch requests.
            $messages[$transition] = $this->dispatch($transition, ['cascade' => 'none']);
        }
        $this->work($routes[$order[0]]);
        self::assertFalse($this->workflow()->can($this->store->load($this->reference), WF::COMPLETE));
        self::assertSame('blocked', $this->redeliver($messages[$order[0]], $routes[$order[0]]));
        $this->work($routes[$order[1]]);
        self::assertTrue($this->workflow()->can($this->store->load($this->reference), WF::COMPLETE));
        self::assertSame('blocked', $this->redeliver($messages[$order[1]], $routes[$order[1]]));

        $this->runner()->start($this->workflow(), $this->store->load($this->reference));
        $completion = $this->queue('image_webhook')->getSent()[1];
        $this->work('image_webhook');
        self::assertSame('blocked', $this->redeliver($completion, 'image_webhook'));
        $this->assertPlaces([WF::COMPLETED]);
        self::assertCount(4, $this->calls, 'Duplicates and premature completion must not repeat side effects.');
        $this->assertWebhookPayload();
    }

    public function testFailedBranchKeepsBothPrerequisitesAndCanBeRetried(): void
    {
        $this->dispatch(WF::DOWNLOAD);
        $this->work('image_download');
        $this->failPath = '/thumbnail';
        $this->work('image_thumbnail');
        $this->assertPlaces([WF::THUMBNAIL_PENDING, WF::DESCRIPTION_PENDING]);
        self::assertCount(1, $this->queue('image_thumbnail')->getRejected());
        self::assertCount(0, $this->queue('image_webhook')->getSent());
        self::assertNull($this->store->load($this->reference)->thumbnail);

        // RunQueue has no retry subscriber: replay explicitly, as a retry transport would.
        $this->failPath = null;
        $this->dispatch(WF::THUMBNAIL);
        $this->work('image_thumbnail');
        $this->work('image_description');
        $this->work('image_webhook');
        $this->assertPlaces([WF::COMPLETED]);
    }

    public function testWebhookFailureRetainsResultsAndUsesTheSameIdempotencyKeyOnRetry(): void
    {
        $this->dispatch(WF::DOWNLOAD);
        foreach (['image_download', 'image_thumbnail', 'image_description'] as $queue) {
            $this->work($queue);
        }
        $this->failPath = '/webhook';
        $this->work('image_webhook');
        $this->assertPlaces([WF::THUMBNAIL_DONE, WF::DESCRIPTION_DONE]);
        self::assertCount(1, $this->queue('image_webhook')->getRejected());
        $this->failPath = null;
        $this->dispatch(WF::COMPLETE);
        $this->work('image_webhook');
        $this->assertPlaces([WF::COMPLETED]);
        $webhooks = array_values(array_filter($this->calls, static fn (array $call) => '/webhook' === $call[0]));
        self::assertCount(2, $webhooks);
        self::assertSame($webhooks[0][1]['normalized_headers']['idempotency-key'], $webhooks[1][1]['normalized_headers']['idempotency-key']);
    }

    private function assertWebhookPayload(): void
    {
        $options = $this->calls[array_key_last($this->calls)][1];
        self::assertSame(['id' => 'run-1', 'thumbnail' => 'https://example.invalid/thumb.jpg', 'description' => 'A museum photograph'], json_decode($options['body'], true, flags: JSON_THROW_ON_ERROR));
        self::assertSame(['Idempotency-Key: image:run-1'], $options['normalized_headers']['idempotency-key']);
    }

    private function assertPlaces(array $places): void
    {
        $marking = $this->store->load($this->reference)->marking;
        self::assertEqualsCanonicalizing($places, array_keys($marking));
        foreach ($marking as $tokens) {
            self::assertSame(1, $tokens);
        }
    }

    private function dispatch(string $transition, array $context = []): Envelope
    {
        return $this->kernel->getContainer()->get('example.'.TransitionDispatcher::class)->dispatch(WF::NAME, $this->reference, $transition, $context);
    }

    private function workflow(): Workflow
    {
        return $this->kernel->getContainer()->get('example.workflow.'.WF::NAME);
    }

    private function runner(): NextTransitionRunner
    {
        return $this->kernel->getContainer()->get('example.'.NextTransitionRunner::class);
    }

    private function queue(string $name): InMemoryTransport
    {
        return $this->kernel->getContainer()->get('example.messenger.transport.'.$name);
    }

    private function work(string $name): void
    {
        self::assertNotEmpty(iterator_to_array($this->queue($name)->get()), 'Expected a queued '.$name.' request.');
        RunQueue::once($name, $this->queue($name), $this->kernel->getContainer()->get('example.messenger.default_bus'));
    }

    private function redeliver(Envelope $envelope, string $transport): string
    {
        return $this->kernel->getContainer()->get('example.messenger.default_bus')
            ->dispatch($envelope->getMessage(), [new ReceivedStamp($transport)])
            ->last(HandledStamp::class)->getResult()->status;
    }
}
