<?php

declare(strict_types=1);

namespace Survos\WorkflowExample\ImageProcessing;

use Survos\WorkflowMessengerBundle\Contract\SubjectStoreInterface;
use Survos\WorkflowMessengerBundle\Message\SubjectReference;

/** JSON persistence for the offline example; the Messenger handler owns locking. */
final readonly class ImageStore implements SubjectStoreInterface
{
    public function __construct(private string $directory)
    {
    }

    public function reference(object $subject): SubjectReference
    {
        if (!$subject instanceof Image) {
            throw new \InvalidArgumentException('Expected an image processing run.');
        }

        return new SubjectReference('image', $subject->id);
    }

    public function load(SubjectReference $reference): ?Image
    {
        $path = $this->path($reference);
        if (!is_file($path)) {
            return null;
        }
        $data = json_decode(file_get_contents($path), true, flags: JSON_THROW_ON_ERROR);
        $image = new Image($reference->id);
        foreach (['marking', 'download', 'thumbnail', 'description'] as $property) {
            $image->$property = $data[$property];
        }

        return $image;
    }

    public function save(SubjectReference $reference, object $subject, array $context = []): void
    {
        if (!$subject instanceof Image || $this->reference($subject) != $reference) {
            throw new \InvalidArgumentException('Image does not match its reference.');
        }
        $path = $this->path($reference);
        if (!is_dir($this->directory) && !mkdir($this->directory, 0775, true) && !is_dir($this->directory)) {
            throw new \RuntimeException('Cannot create image storage.');
        }
        $temporary = tempnam($this->directory, 'image-');
        try {
            if (false === $temporary || false === file_put_contents($temporary, json_encode($subject, JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT)) || !rename($temporary, $path)) {
                throw new \RuntimeException('Cannot save image.');
            }
        } finally {
            if (is_string($temporary) && is_file($temporary)) {
                unlink($temporary);
            }
        }
    }

    private function path(SubjectReference $reference): string
    {
        if ('image' !== $reference->type) {
            throw new \InvalidArgumentException('Unsupported subject type.');
        }

        return $this->directory.'/'.hash('sha256', $reference->id).'.json';
    }
}
