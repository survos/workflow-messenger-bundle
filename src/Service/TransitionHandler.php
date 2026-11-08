<?php

declare(strict_types=1);

namespace Survos\WorkflowMessengerBundle\Service;

use Survos\WorkflowMessengerBundle\Contract\SubjectStoreInterface;
use Survos\WorkflowMessengerBundle\Message\TransitionRequest;
use Survos\WorkflowMessengerBundle\Message\TransitionResult;
use Symfony\Component\Lock\LockFactory;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use Symfony\Component\Messenger\Exception\RecoverableMessageHandlingException;
use Symfony\Component\Messenger\Exception\UnrecoverableMessageHandlingException;
use Symfony\Component\Workflow\Exception\NotEnabledTransitionException;

/**
 * Applies queued transitions individually or in configured batches. Requests for the same subject never run concurrently: the subject is loaded, applied, saved and continued under
 * a per-subject lock, so `can()` holds at apply time and no flush overwrites another.
 * A busy subject is retried shortly. The lock is re-entrant within this process, for
 * a sync continuation of the same subject inside its own handler.
 */
#[AsMessageHandler]
final class TransitionHandler implements \Symfony\Component\Messenger\Handler\BatchHandlerInterface
{
    /** @var array<string, list<array{TransitionRequest, \Symfony\Component\Messenger\Handler\Acknowledger}>> */
    private array $pending = [];
    /** @var array<string, array{\Symfony\Component\Clock\ClockInterface, int}> */
    private array $lastReceived = [];

    /** @var array<string, int> lock resource => nesting depth held by this process */
    private array $held = [];

    public function __construct(
        private readonly SubjectStoreInterface $store,
        private readonly WorkflowLocator $workflows,
        private readonly TransitionRouter $router,
        private readonly LockFactory $locks,
        private readonly ?\Survos\WorkflowExtrasBundle\Service\NextTransitionRunner $next = null,
        private readonly ?\Symfony\Contracts\EventDispatcher\EventDispatcherInterface $events = null,
        private readonly ?TransitionDispatcher $dispatcher = null,
    ) {
    }

    public function __invoke(TransitionRequest $request, ?\Symfony\Component\Messenger\Handler\Acknowledger $ack = null): TransitionResult|int
    {
        if (null === $ack) {
            return $this->run($request);
        }
        try {
            if (1 !== $request->version) {
                throw new UnrecoverableMessageHandlingException('Unsupported workflow request version.');
            }
            try {
                $workflow = $this->workflows->get($request->workflow);
                $size = $this->router->batchSize($workflow, $request->transition);
                $this->router->transport($workflow, $request->transition, ($request->context['cascade'] ?? null) === 'sync');
            } catch (\InvalidArgumentException $error) {
                throw new UnrecoverableMessageHandlingException($error->getMessage(), previous: $error);
            }
            if (null === $size || ($request->context['cascade'] ?? null) === 'sync' || ($request->context[\Survos\WorkflowMessengerBundle\Event\BatchTransitionEvent::CONTEXT_UNBATCHED] ?? false)) {
                $ack->ack($this->run($request));
            } else {
                $key = json_encode([$request->workflow, $request->subject->type, $request->transition], JSON_THROW_ON_ERROR);
                $this->pending[$key][] = [$request, $ack];
                $this->lastReceived[$key] = [$ack->clock, $ack->clock->now()->getTimestamp()];
                if (count($this->pending[$key]) >= $size) {
                    $this->flushGroup($key);
                }
            }
        } catch (\Throwable $error) {
            if (!$ack->isAcknowledged()) {
                $ack->nack($error);
            }
        }

        return array_sum(array_map('count', $this->pending));
    }

    public function flush(bool $force): void
    {
        foreach (array_keys($this->pending) as $key) {
            [$clock, $received] = $this->lastReceived[$key];
            if ($force || $clock->now()->getTimestamp() - $received >= 5) {
                $this->flushGroup($key);
            }
        }
    }

    private function flushGroup(string $key): void
    {
        $jobs = $this->pending[$key];
        unset($this->pending[$key], $this->lastReceived[$key]);
        try {
            $references = array_map(static fn ($job) => $job[0]->subject, $jobs);
            $this->withLocks($references, function () use ($jobs, $references): void {
                try {
                    $this->processBatch($jobs);
                } catch (\Throwable $error) {
                    if ($this->store instanceof \Survos\WorkflowMessengerBundle\Contract\BatchSubjectStoreInterface) {
                        $this->store->discardBatch($references);
                    }
                    throw $error;
                }
            });
        } catch (\Throwable $error) {
            foreach ($jobs as [, $ack]) {
                if (!$ack->isAcknowledged()) {
                    $ack->nack($error);
                }
            }
        }
    }

    private function run(TransitionRequest $request): TransitionResult
    {
        if (1 !== $request->version) {
            throw new UnrecoverableMessageHandlingException('Unsupported workflow request version.');
        }
        try {
            $workflow = $this->workflows->get($request->workflow);
            $this->router->transport($workflow, $request->transition, ($request->context['cascade'] ?? null) === 'sync');
        } catch (\InvalidArgumentException $e) {
            throw new UnrecoverableMessageHandlingException($e->getMessage(), previous: $e);
        }

        return $this->withLocks([$request->subject], function () use ($request, $workflow): TransitionResult {
            try {
                return $this->handle($request, $workflow);
            } catch (\Throwable $error) {
                // completed listeners run after the in-memory marking changes. A failed
                // continuation must not leave those changes for the next message to flush.
                if ($this->store instanceof \Survos\WorkflowMessengerBundle\Contract\BatchSubjectStoreInterface) {
                    $this->store->discardBatch([$request->subject]);
                }
                throw $error;
            }
        });
    }

    /** Acquire before loading; batch and single work share the same re-entrant lock scope. */
    private function withLocks(array $references, callable $work): mixed
    {
        $resources = [];
        foreach ($references as $reference) {
            $resources[sprintf('survos_workflow:%s:%s', $reference->type, $reference->id)] = $reference;
        }
        ksort($resources);
        $entered = [];
        $acquired = [];
        try {
            foreach ($resources as $resource => $reference) {
                if (!isset($this->held[$resource])) {
                    $lock = $this->locks->createLock($resource, ttl: null);
                    if (!$lock->acquire()) {
                        throw new RecoverableMessageHandlingException(sprintf('A transition of %s "%s" is already running.', $reference->type, $reference->id), retryDelay: 1000);
                    }
                    $acquired[$resource] = $lock;
                    $this->held[$resource] = 0;
                }
                ++$this->held[$resource];
                $entered[] = $resource;
            }

            return $work();
        } finally {
            foreach (array_reverse($entered) as $resource) {
                if (0 === --$this->held[$resource]) {
                    unset($this->held[$resource]);
                    $acquired[$resource]->release();
                }
            }
        }
    }

    private function processBatch(array $jobs): void
    {
        $first = $jobs[0][0];
        $workflow = $this->workflows->get($first->workflow);
        $grouped = [];
        foreach ($jobs as [$request, $ack]) {
            $grouped[$request->subject->id][] = [$request, $ack];
        }
        $ready = [];
        foreach ($grouped as $id => $duplicates) {
            $request = $duplicates[0][0];
            $subject = $this->store->load($request->subject);
            if (null === $subject) {
                $error = new UnrecoverableMessageHandlingException(sprintf('Missing subject for workflow "%s" transition "%s".', $request->workflow, $request->transition));
                foreach ($duplicates as [, $ack]) {
                    $ack->nack($error);
                }
                continue;
            }
            $previous = null;
            foreach ($workflow->getEnabledTransitions($subject) as $transition) {
                if ($transition->getName() === $request->transition) {
                    $previous = $transition;
                    break;
                }
            }
            if (null === $previous) {
                $reasons = [];
                $blockers = $workflow->buildTransitionBlockerList($subject, $request->transition);
                $this->resumeAfterCommittedTransition($request, $workflow, $subject, $blockers);
                foreach ($blockers as $blocker) {
                    $reasons[] = $blocker->getMessage();
                }
                foreach ($duplicates as [$duplicate, $ack]) {
                    $ack->ack(new TransitionResult($duplicate->requestId, 'blocked', $reasons));
                }
                continue;
            }
            $ready[$id] = [$request, $subject, $previous];
        }
        if ([] === $ready) {
            return;
        }
        $event = new \Survos\WorkflowMessengerBundle\Event\BatchTransitionEvent(
            $first->workflow,
            $first->transition,
            array_values(array_map(static fn ($row) => $row[1], $ready)),
            array_map(static fn ($row) => $row[0]->context, $ready),
        );
        if (null === $this->events) {
            throw new \LogicException('Batch transitions require an event dispatcher.');
        }
        $this->events->dispatch($event, $event::name($first->workflow, $first->transition));
        $applied = [];
        foreach ($ready as $id => [$request, $subject, $previous]) {
            if ($event->isReleased($subject)) {
                if (null === $this->dispatcher) {
                    throw new \LogicException('Releasing a batch subject requires a transition dispatcher.');
                }
                $context = $request->context;
                unset($context[$event::CONTEXT_BATCHED]);
                $context[$event::CONTEXT_UNBATCHED] = true;
                $this->dispatcher->dispatch($request->workflow, $request->subject, $request->transition, $context);
                foreach ($grouped[$id] as [$duplicate, $ack]) {
                    $ack->ack(new TransitionResult($duplicate->requestId, 'released'));
                }
                continue;
            }
            $context = $request->context;
            unset($context[$event::CONTEXT_BATCHED]);
            if (!$event->isSkipped($subject)) {
                $context[$event::CONTEXT_BATCHED] = true;
            }
            $workflow->apply($subject, $request->transition, array_replace($context, ['workflow_extras.defer' => true]));
            $applied[$id] = [$request, $subject, $previous, $context];
        }
        $changes = array_values(array_map(static fn ($row) => [$row[0]->subject, $row[1], $row[3]], $applied));
        if ($this->store instanceof \Survos\WorkflowMessengerBundle\Contract\BatchSubjectStoreInterface) {
            $this->store->saveBatch($changes);
        } else {
            foreach ($changes as [$reference, $subject, $context]) {
                $this->store->save($reference, $subject, $context);
            }
        }
        // Every marking is durable before any automatic continuation or successful ack.
        foreach ($applied as $id => [$request, $subject, $previous]) {
            try {
                $this->next?->continue($workflow, $subject, $request->context, $previous);
                foreach ($grouped[$id] as [$duplicate, $ack]) {
                    $ack->ack(new TransitionResult($duplicate->requestId, 'applied'));
                }
            } catch (\Throwable $error) {
                if ($this->store instanceof \Survos\WorkflowMessengerBundle\Contract\BatchSubjectStoreInterface) {
                    $this->store->discardBatch([$request->subject]);
                }
                foreach ($grouped[$id] as [, $ack]) {
                    if (!$ack->isAcknowledged()) {
                        $ack->nack($error);
                    }
                }
            }
        }
    }

    private function handle(TransitionRequest $request, \Symfony\Component\Workflow\WorkflowInterface $workflow): TransitionResult
    {
        $subject = $this->store->load($request->subject);
        if (null === $subject) {
            // Fail loudly, never ack: retrying cannot make the subject appear, so the failure transport.
            throw new UnrecoverableMessageHandlingException(sprintf('Missing subject for workflow "%s" transition "%s".', $request->workflow, $request->transition));
        }
        $previous = null;
        foreach ($workflow->getEnabledTransitions($subject) as $transition) {
            if ($transition->getName() === $request->transition) {
                $previous = $transition;
                break;
            }
        }
        try {
            $workflow->apply($subject, $request->transition, array_replace($request->context, ['workflow_extras.defer' => true]));
        } catch (NotEnabledTransitionException $e) {
            $this->resumeAfterCommittedTransition($request, $workflow, $subject, $e->getTransitionBlockerList());
            $reasons = [];
            foreach ($e->getTransitionBlockerList() as $blocker) {
                $reasons[] = $blocker->getMessage();
            }

            return new TransitionResult($request->requestId, 'blocked', $reasons);
        }
        $this->store->save($request->subject, $subject, $request->context);

        $this->next?->continue($workflow, $subject, $request->context, $previous);

        return new TransitionResult($request->requestId, 'applied');
    }

    private function resumeAfterCommittedTransition(TransitionRequest $request, \Symfony\Component\Workflow\WorkflowInterface $workflow, object $subject, \Symfony\Component\Workflow\TransitionBlockerList $blockers): void
    {
        // A previous attempt may have saved the transition before its continuation
        // failed. Resume from the durable current places, not the obsolete transition.
        // Guard failures must still block work without advancing another branch.
        if ($blockers->has(\Symfony\Component\Workflow\TransitionBlocker::BLOCKED_BY_MARKING)) {
            $this->next?->continue($workflow, $subject, $request->context);
        }
    }
}
