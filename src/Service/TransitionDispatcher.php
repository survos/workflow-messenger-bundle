<?php

declare(strict_types=1);

namespace Survos\WorkflowMessengerBundle\Service;

use Survos\WorkflowMessengerBundle\Message\SubjectReference;
use Survos\WorkflowMessengerBundle\Message\TransitionRequest;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Messenger\Stamp\TransportNamesStamp;

final readonly class TransitionDispatcher
{
    public function __construct(private MessageBusInterface $bus, private WorkflowLocator $workflows, private TransitionRouter $router)
    {
    }

    /** @param array<array-key, scalar|array<array-key, mixed>|null> $context Plain data, recursively validated by TransitionRequest. */
    public function dispatch(string $workflow, SubjectReference $subject, string $transition, array $context = []): Envelope
    {
        $transport = $this->router->transport($this->workflows->get($workflow), $transition, ($context['cascade'] ?? null) === 'sync');
        $request = new TransitionRequest($workflow, $subject, $transition, $context, bin2hex(random_bytes(16)));

        return $this->bus->dispatch($request, [new TransportNamesStamp(null === $transport ? [] : [$transport])]);
    }
}
