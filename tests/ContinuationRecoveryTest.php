<?php

declare(strict_types=1);

namespace Survos\WorkflowMessengerBundle\Tests;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Survos\WorkflowExtrasBundle\Contract\TransitionExecutorInterface;
use Survos\WorkflowExtrasBundle\Service\NextTransitionResolver;
use Survos\WorkflowExtrasBundle\Service\NextTransitionRunner;
use Survos\WorkflowMessengerBundle\Contract\BatchSubjectStoreInterface;
use Survos\WorkflowMessengerBundle\Message\SubjectReference;
use Survos\WorkflowMessengerBundle\Message\TransitionRequest;
use Survos\WorkflowMessengerBundle\Service\TransitionHandler;
use Survos\WorkflowMessengerBundle\Service\TransitionRouter;
use Survos\WorkflowMessengerBundle\Service\WorkflowLocator;
use Symfony\Component\DependencyInjection\ServiceLocator;
use Symfony\Component\EventDispatcher\EventDispatcher;
use Symfony\Component\Lock\LockFactory;
use Symfony\Component\Lock\Store\InMemoryStore;
use Symfony\Component\Messenger\Handler\Acknowledger;
use Symfony\Component\Workflow\Definition;
use Symfony\Component\Workflow\MarkingStore\MethodMarkingStore;
use Symfony\Component\Workflow\Metadata\InMemoryMetadataStore;
use Symfony\Component\Workflow\StateMachine;
use Symfony\Component\Workflow\Transition;
use Symfony\Component\Workflow\WorkflowInterface;

final class ContinuationRecoveryTest extends TestCase
{
    public static function modes(): iterable
    {
        yield 'single' => [false];
        yield 'batch' => [true];
    }

    #[DataProvider('modes')]
    public function testRetryResumesContinuationAfterOriginalTransitionWasSaved(bool $batch): void
    {
        [$handler, $store, $executor] = $this->fixture($batch);
        $executor->fail = true;
        $request = new TransitionRequest('example', new SubjectReference('item', '1'), 'load', requestId: 'test');
        $first = new Acknowledger('test');
        $handler($request, $first);
        self::assertInstanceOf(\RuntimeException::class, $first->getError());
        self::assertSame('loaded', $store->saved->marking);
        self::assertNull($store->managed, 'Continuation failures must discard unsaved state too.');
        $executor->fail = false;
        $retry = new Acknowledger('test');
        $handler($request, $retry);
        self::assertNull($retry->getError());
        self::assertSame(['finish', 'finish'], $executor->attempts);
        self::assertSame(1, $store->saves, 'Do not repeat the committed transition.');
    }

    #[DataProvider('modes')]
    public function testFailedCompletedListenerCannotLeakDirtyStateIntoNextSave(bool $batch): void
    {
        [$handler, $store, , $events] = $this->fixture($batch);
        $events->addListener('workflow.example.completed.load', static function (): void { throw new \RuntimeException('lock busy'); });
        $ack = new Acknowledger('test');
        $handler(new TransitionRequest('example', new SubjectReference('item', '1'), 'load', requestId: 'test'), $ack);
        self::assertInstanceOf(\RuntimeException::class, $ack->getError());
        self::assertSame('new', $store->saved->marking);
        self::assertNull($store->managed, 'Failed changes must be discarded before another message can flush them.');
    }

    #[DataProvider('modes')]
    public function testGuardBlockedRequestDoesNotStartAnotherTransition(bool $batch): void
    {
        [$handler, , $executor, $events] = $this->fixture($batch);
        $events->addListener('workflow.example.guard.load', static function ($event): void { $event->setBlocked(true, 'Not authorized'); });
        $ack = new Acknowledger('test');
        $handler(new TransitionRequest('example', new SubjectReference('item', '1'), 'load', requestId: 'test'), $ack);
        self::assertNull($ack->getError());
        self::assertSame('blocked', $ack->getResult()->status);
        self::assertSame([], $executor->attempts);
    }

    private function fixture(bool $batch): array
    {
        $store = new RecoveryStore();
        $load = new Transition('load', 'new', 'loaded');
        $metadata = new \SplObjectStorage();
        $metadata[$load] = $batch ? ['async' => true, 'transport' => 'queue', 'batch' => 1] : [];
        $events = new EventDispatcher();
        $workflow = new StateMachine(new Definition(['new', 'loaded', 'done'], [$load, new Transition('finish', 'loaded', 'done')], 'new', new InMemoryMetadataStore([], ['loaded' => ['next' => ['finish']]], $metadata)), new MethodMarkingStore(true, 'marking'), $events, 'example');
        $executor = new class implements TransitionExecutorInterface {
            public bool $fail = false;
            public array $attempts = [];

            public function requiresSavedSubject(): bool
            {
                return true;
            }

            public function synchronously(callable $callback): mixed
            {
                return $callback();
            }

            public function execute(WorkflowInterface $workflow, object $subject, string $transition, array $context = []): void
            {
                $this->attempts[] = $transition;
                if ($this->fail) {
                    throw new \RuntimeException('continuation temporarily unavailable');
                }
            }
        };
        $handler = new TransitionHandler($store, new WorkflowLocator(new ServiceLocator(['example' => static fn () => $workflow])), new TransitionRouter(null, ['queue']), new LockFactory(new InMemoryStore()), new NextTransitionRunner(new NextTransitionResolver(), $executor), $events);

        return [$handler, $store, $executor, $events];
    }
}

final class RecoveryStore implements BatchSubjectStoreInterface
{
    public object $saved;
    public ?object $managed = null;
    public int $saves = 0;

    public function __construct()
    {
        $this->saved = (object) ['marking' => 'new'];
    }

    public function reference(object $subject): SubjectReference
    {
        return new SubjectReference('item', '1');
    }

    public function load(SubjectReference $reference): ?object
    {
        return $this->managed ??= clone $this->saved;
    }

    public function save(SubjectReference $reference, object $subject, array $context = []): void
    {
        ++$this->saves;
        $this->saved = clone $subject;
    }

    public function saveBatch(array $changes): void
    {
        foreach ($changes as [$reference, $subject, $context]) {
            $this->save($reference, $subject, $context);
        }
    }

    public function discardBatch(array $references): void
    {
        $this->managed = null;
    }
}
