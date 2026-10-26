<?php

declare(strict_types=1);

namespace Survos\WorkflowExample\ImageProcessing;

final class Image
{
    /** @var array<string, int> A workflow marking is a place => token count map. */
    public array $marking = [];
    public ?string $download = null;
    public ?string $thumbnail = null;
    public ?string $description = null;

    public function __construct(public readonly string $id)
    {
    }
}
