<?php

declare(strict_types=1);

namespace Survos\WorkflowMessengerBundle\Tests;

use PHPUnit\Framework\TestCase;
use Survos\WorkflowMessengerBundle\Contract\BatchSubjectStoreInterface;
use Survos\WorkflowMessengerBundle\Event\BatchTransitionEvent;
use Survos\WorkflowMessengerBundle\Message\SubjectReference;
use Survos\WorkflowMessengerBundle\Message\TransitionRequest;
use Survos\WorkflowMessengerBundle\Service\TransitionDispatcher;
use Survos\WorkflowMessengerBundle\Service\TransitionHandler;
use Survos\WorkflowMessengerBundle\Service\TransitionRouter;
use Survos\WorkflowMessengerBundle\Service\WorkflowLocator;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\DependencyInjection\ServiceLocator;
use Symfony\Component\EventDispatcher\EventDispatcher;
use Symfony\Component\Lock\LockFactory;
use Symfony\Component\Lock\Store\InMemoryStore;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\Exception\RecoverableMessageHandlingException;
use Symfony\Component\Messenger\Exception\UnrecoverableMessageHandlingException;
use Symfony\Component\Messenger\Handler\Acknowledger;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Workflow\Definition;
use Symfony\Component\Workflow\Event\TransitionEvent;
use Symfony\Component\Workflow\MarkingStore\MethodMarkingStore;
use Symfony\Component\Workflow\Metadata\InMemoryMetadataStore;
use Symfony\Component\Workflow\StateMachine;
use Symfony\Component\Workflow\Transition;

final class BatchTransitionHandlerTest extends TestCase
{
    public function testBatchDeduplicatesAndSavesBeforeAcknowledging(): void
    {
        [$handler, $store, $events] = $this->fixture(3);
        $batches = [];
        $events->addListener(BatchTransitionEvent::name('media', 'dispatch'), static function (BatchTransitionEvent $event) use (&$batches): void {
            $batches[] = $event->subjects;
        });
        $acks = [];
        foreach (['1', '1', '2'] as $i => $id) {
            $ack = new Acknowledger('test', static function ($error, $result) use ($store): void {
                self::assertNull($error);
                self::assertSame('applied', $result->status);
                self::assertSame(['1' => 'done', '2' => 'done'], $store->saved);
            });
            $acks[] = $ack;
            $handler($this->request($id, requestId: 'request-'.$i), $ack);
        }
        self::assertCount(1, $batches);
        self::assertCount(2, $batches[0]);
        self::assertSame(1, $store->saves);
        self::assertSame(['request-0', 'request-1', 'request-2'], array_map(static fn ($ack) => $ack->getResult()->requestId, $acks));
    }

    public function testPartialBatchFlushesAfterIdleTimeout(): void
    {
        [$handler, $store] = $this->fixture(500);
        $clock = new MockClock();
        $ack = new Acknowledger('test', clock: $clock);
        self::assertSame(1, $handler($this->request('1'), $ack));
        $handler->flush(false);
        self::assertFalse($ack->isAcknowledged());
        $clock->sleep(5);
        $handler->flush(false);
        self::assertTrue($ack->isAcknowledged());
        self::assertSame(['1' => 'done'], $store->saved);
    }

    public function testSkipRunsPerItemAndReleaseQueuesUnbatchedRequest(): void
    {
        [$handler, $store, $events, , $bus] = $this->fixture(2);
        $contexts = [];
        $events->addListener(BatchTransitionEvent::name('media', 'dispatch'), static function (BatchTransitionEvent $event): void {
            $event->skip($event->subjects[0]);
            $event->release($event->subjects[1]);
        });
        $events->addListener('workflow.media.transition.dispatch', static function (TransitionEvent $event) use (&$contexts): void {
            $contexts[] = $event->getContext();
        });
        $first = new Acknowledger('test');
        $second = new Acknowledger('test');
        $handler($this->request('1'), $first);
        $handler($this->request('2'), $second);
        self::assertSame('applied', $first->getResult()->status);
        self::assertSame('released', $second->getResult()->status);
        self::assertArrayNotHasKey(BatchTransitionEvent::CONTEXT_BATCHED, $contexts[0]);
        self::assertSame(['1' => 'done'], $store->saved);
        self::assertCount(1, $bus->messages);
        $released = $bus->messages[0]->getMessage();
        self::assertTrue($released->context[BatchTransitionEvent::CONTEXT_UNBATCHED]);
        $third = new Acknowledger('test');
        $handler($released, $third);
        self::assertTrue($third->isAcknowledged());
        self::assertSame('applied', $third->getResult()->status);
        self::assertCount(2, $contexts);
    }

    public function testListenerFailureNacksEveryRequestWithoutSaving(): void
    {
        [$handler, $store, $events] = $this->fixture(2);
        $error = new \RuntimeException('bulk API failed');
        $events->addListener(BatchTransitionEvent::name('media', 'dispatch'), static fn () => throw $error);
        $first = new Acknowledger('test');
        $second = new Acknowledger('test');
        $handler($this->request('1'), $first);
        $handler($this->request('2'), $second);
        self::assertSame($error, $first->getError());
        self::assertSame($error, $second->getError());
        self::assertSame(0, $store->saves);
    }

    public function testPersistenceFailureDoesNotAcknowledgeSuccessfulApplications(): void
    {
        [$handler, $store] = $this->fixture(2);
        $store->failSave = true;
        $first = new Acknowledger('test');
        $second = new Acknowledger('test');
        $handler($this->request('1'), $first);
        $handler($this->request('2'), $second);
        self::assertInstanceOf(\RuntimeException::class, $first->getError());
        self::assertSame($first->getError(), $second->getError());
        self::assertSame([], $store->saved);
        self::assertSame('new', $store->subjects['1']->marking);
        self::assertSame('new', $store->subjects['2']->marking);
    }

    public function testPerItemFailureDiscardsEarlierUnsavedMarkings(): void
    {
        [$handler, $store, $events] = $this->fixture(2);
        $events->addListener('workflow.media.transition.dispatch', static function (TransitionEvent $event): void {
            if ('2' === $event->getSubject()->id) {
                throw new \RuntimeException('per-item listener failed');
            }
        });
        $first = new Acknowledger('test');
        $second = new Acknowledger('test');
        $handler($this->request('1'), $first);
        $handler($this->request('2'), $second);
        self::assertInstanceOf(\RuntimeException::class, $first->getError());
        self::assertSame($first->getError(), $second->getError());
        self::assertSame(0, $store->saves);
        self::assertSame('new', $store->subjects['1']->marking);
    }

    public function testBusySubjectPreventsBatchSideEffectsAndReleasesOtherLocks(): void
    {
        [$handler, $store, $events, $locks] = $this->fixture(2);
        $lock = $locks->createLock('survos_workflow:item:2');
        self::assertTrue($lock->acquire());
        $calls = 0;
        $events->addListener(BatchTransitionEvent::name('media', 'dispatch'), static function () use (&$calls): void { ++$calls; });
        $first = new Acknowledger('test');
        $second = new Acknowledger('test');
        $handler($this->request('1'), $first);
        $handler($this->request('2'), $second);
        self::assertInstanceOf(RecoverableMessageHandlingException::class, $first->getError());
        self::assertInstanceOf(RecoverableMessageHandlingException::class, $second->getError());
        self::assertSame(0, $calls);
        self::assertSame(0, $store->saves);
        $other = $locks->createLock('survos_workflow:item:1');
        self::assertTrue($other->acquire(), 'The failed group must release locks acquired before the busy subject.');
        $other->release();
        $lock->release();
    }

    public function testMissingAndBlockedSubjectsNeverReachBulkListener(): void
    {
        [$handler, $store, $events] = $this->fixture(2);
        $store->subjects['1']->marking = 'done';
        $calls = 0;
        $events->addListener(BatchTransitionEvent::name('media', 'dispatch'), static function () use (&$calls): void { ++$calls; });
        $first = new Acknowledger('test');
        $second = new Acknowledger('test');
        $handler($this->request('1'), $first);
        $handler($this->request('missing'), $second);
        self::assertSame('blocked', $first->getResult()->status);
        self::assertInstanceOf(UnrecoverableMessageHandlingException::class, $second->getError());
        self::assertSame(0, $calls);
    }

    public function testDirectCallRunsImmediatelyWithoutBatchFlag(): void
    {
        [$handler, $store, $events] = $this->fixture(500);
        $contexts = [];
        $events->addListener('workflow.media.transition.dispatch', static function (TransitionEvent $event) use (&$contexts): void { $contexts[] = $event->getContext(); });
        self::assertSame('applied', $handler($this->request('1'))->status);
        self::assertSame(['1' => 'done'], $store->saved);
        self::assertArrayNotHasKey(BatchTransitionEvent::CONTEXT_BATCHED, $contexts[0]);
    }

    public function testMessengerMiddlewareDefersAcknowledgmentUntilBatchIsSaved(): void
    {
        [$handler, $store] = $this->fixture(2);
        $bus = new \Symfony\Component\Messenger\MessageBus([
            new \Symfony\Component\Messenger\Middleware\HandleMessageMiddleware(
                new \Symfony\Component\Messenger\Handler\HandlersLocator([TransitionRequest::class => [$handler]]),
            ),
        ]);
        $acknowledged = [];
        $stamp = new \Symfony\Component\Messenger\Stamp\AckStamp(static function (Envelope $envelope, ?\Throwable $error) use (&$acknowledged, $store): void {
            self::assertNull($error);
            self::assertSame(['1' => 'done', '2' => 'done'], $store->saved);
            $acknowledged[] = $envelope;
        });
        $bus->dispatch($this->request('1'), [$stamp]);
        self::assertCount(0, $acknowledged);
        $bus->dispatch($this->request('2'), [$stamp]);
        self::assertCount(2, $acknowledged);
    }

    public function testBatchSizesMustAgreeForAlternativeSources(): void
    {
        $first = new Transition('dispatch', 'new', 'done');
        $second = new Transition('dispatch', 'failed', 'done');
        $metadata = new \SplObjectStorage();
        $metadata[$first] = ['async' => true, 'batch' => 500];
        $metadata[$second] = ['async' => true, 'batch' => 100];
        $workflow = new StateMachine(new Definition(['new', 'failed', 'done'], [$first, $second], 'new', new InMemoryMetadataStore([], [], $metadata)));
        $this->expectException(\InvalidArgumentException::class);
        (new TransitionRouter())->batchSize($workflow, 'dispatch');
    }

    public function testGroupPersistencePrecedesEveryContinuation(): void
    {
        [$handler, $store] = $this->fixture(2, continue: true);
        $first = new Acknowledger('test');
        $second = new Acknowledger('test');
        $handler($this->request('1'), $first);
        $handler($this->request('2'), $second);
        self::assertNull($first->getError());
        self::assertNull($second->getError());
        self::assertSame(['1', '2'], $store->continued);
    }

    public function testTheRequestIdIsTheMessageIdOfTheDispatchedEnvelope(): void
    {
        [$handler, , , , $bus] = $this->fixture(2);
        $dispatcher = (new \ReflectionProperty($handler, 'dispatcher'))->getValue($handler);

        $envelope = $dispatcher->dispatch('media', new SubjectReference('item', '1'), 'dispatch');

        $requestId = $envelope->getMessage()->requestId;
        self::assertSame($requestId, $envelope->last(\Symfony\Component\Messenger\Stamp\MessageIdStamp::class)?->getId());
        self::assertSame($envelope, $bus->messages[0]);
    }

    private function request(string $id, string $requestId = 'request'): TransitionRequest
    {
        return new TransitionRequest('media', new SubjectReference('item', $id), 'dispatch', requestId: $requestId);
    }

    private function fixture(int $size, bool $continue = false): array
    {
        $store = new BatchMemoryStore();
        $events = new EventDispatcher();
        $transition = new Transition('dispatch', 'new', 'done');
        $metadata = new \SplObjectStorage();
        $metadata[$transition] = ['async' => true, 'transport' => 'media.dispatch', 'batch' => $size];
        $workflow = new StateMachine(new Definition(['new', 'done', 'archived'], [$transition, new Transition('archive', 'done', 'archived')], 'new', new InMemoryMetadataStore([], $continue ? ['done' => ['next' => ['archive']]] : [], $metadata)), new MethodMarkingStore(true, 'marking'), $events, 'media');
        $locator = new WorkflowLocator(new ServiceLocator(['media' => static fn () => $workflow]));
        $router = new TransitionRouter(null, ['media.dispatch']);
        $locks = new LockFactory(new InMemoryStore());
        $bus = new class implements MessageBusInterface {
            public array $messages = [];

            public function dispatch(object $message, array $stamps = []): Envelope
            {
                return $this->messages[] = Envelope::wrap($message, $stamps);
            }
        };
        $next = null;
        if ($continue) {
            $executor = $this->createMock(\Survos\WorkflowExtrasBundle\Contract\TransitionExecutorInterface::class);
            $executor->expects(self::exactly(2))->method('execute')->willReturnCallback(static function ($workflow, $subject, $transition, $context) use ($store): void {
                self::assertSame(['1' => 'done', '2' => 'done'], $store->saved);
                self::assertArrayNotHasKey(BatchTransitionEvent::CONTEXT_BATCHED, $context);
                $store->continued[] = $subject->id;
            });
            $next = new \Survos\WorkflowExtrasBundle\Service\NextTransitionRunner(new \Survos\WorkflowExtrasBundle\Service\NextTransitionResolver(), $executor);
        }
        $handler = new TransitionHandler($store, $locator, $router, $locks, next: $next, events: $events, dispatcher: new TransitionDispatcher($bus, $locator, $router));

        return [$handler, $store, $events, $locks, $bus];
    }
}

final class BatchMemoryStore implements BatchSubjectStoreInterface
{
    public array $subjects;
    public array $saved = [];
    public array $continued = [];
    public int $saves = 0;
    public bool $failSave = false;

    public function __construct()
    {
        $this->subjects = ['1' => new BatchItem('1'), '2' => new BatchItem('2')];
    }

    public function reference(object $subject): SubjectReference
    {
        return new SubjectReference('item', $subject->id);
    }

    public function load(SubjectReference $reference): ?object
    {
        return $this->subjects[$reference->id] ?? null;
    }

    public function save(SubjectReference $reference, object $subject, array $context = []): void
    {
        $this->saveBatch([[$reference, $subject, $context]]);
    }

    public function discardBatch(array $references): void
    {
        foreach ($references as $reference) {
            if (isset($this->subjects[$reference->id])) {
                $this->subjects[$reference->id]->marking = $this->saved[$reference->id] ?? 'new';
            }
        }
    }

    public function saveBatch(array $changes): void
    {
        if ($this->failSave) {
            throw new \RuntimeException('flush failed');
        }
        ++$this->saves;
        foreach ($changes as [$reference, $subject]) {
            $this->saved[$reference->id] = $subject->marking;
        }
    }
}

final class BatchItem
{
    public string $marking = 'new';

    public function __construct(public string $id)
    {
    }
}
