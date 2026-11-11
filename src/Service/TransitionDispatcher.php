<?php

declare(strict_types=1);

namespace Survos\WorkflowMessengerBundle\Service;

use Survos\WorkflowMessengerBundle\Message\SubjectReference;
use Survos\WorkflowMessengerBundle\Message\TransitionRequest;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Messenger\Stamp\MessageIdStamp;
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
        $requestId = bin2hex(random_bytes(16));
        $request = new TransitionRequest($workflow, $subject, $transition, $context, $requestId);

        // The request ID is the message's own ID, so a TransitionResult, a log line and the
        // causation/correlation stamps of everything it dispatches name the same message.
        return $this->bus->dispatch($request, [new MessageIdStamp($requestId), new TransportNamesStamp(null === $transport ? [] : [$transport])]);
    }
}
