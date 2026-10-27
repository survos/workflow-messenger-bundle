<?php

declare(strict_types=1);

namespace Survos\WorkflowMessengerBundle\Message;

/** Handler result; inspect through HandledStamp or a worker event subscriber. */
final readonly class TransitionResult
{
    /** @param list<string> $reasons Workflow blocker messages. */
    public function __construct(public string $requestId, public string $status, public array $reasons = [])
    {
    }
}
