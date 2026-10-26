<?php

declare(strict_types=1);

namespace Survos\WorkflowExample\ImageProcessing;

use Survos\WorkflowExtrasBundle\Attribute\AsWorkflow;
use Survos\WorkflowExtrasBundle\Attribute\Place;
use Survos\WorkflowMessengerBundle\Attribute\Transition;
use Symfony\Component\Workflow\Attribute\AsTransitionListener;
use Symfony\Component\Workflow\Event\TransitionEvent;
use Symfony\Component\Workflow\WorkflowType;
use Symfony\Contracts\HttpClient\HttpClientInterface;

#[AsWorkflow(name: self::NAME, type: WorkflowType::Workflow, supports: Image::class,
    description: 'Download, prepare independent thumbnail and description results, then notify')]
final readonly class ImageWorkflow
{
    public const NAME = 'image_processing';

    #[Place('Ready to download', initial: true, next: [self::DOWNLOAD])]
    public const NEW = 'new';

    #[Place('Thumbnail needed', next: [self::THUMBNAIL])]
    public const THUMBNAIL_PENDING = 'thumbnail_pending';

    #[Place('Description needed', next: [self::DESCRIBE])]
    public const DESCRIPTION_PENDING = 'description_pending';

    #[Place('Thumbnail available', next: [self::COMPLETE])]
    public const THUMBNAIL_DONE = 'thumbnail_done';

    #[Place('Description available', next: [self::COMPLETE])]
    public const DESCRIPTION_DONE = 'description_done';

    #[Place('Completion webhook delivered')]
    public const COMPLETED = 'completed';

    #[Transition(self::NEW, [self::THUMBNAIL_PENDING, self::DESCRIPTION_PENDING],
        'Download the source image and enable both branches', async: true, transport: 'image_download')]
    public const DOWNLOAD = 'download';

    #[Transition(self::THUMBNAIL_PENDING, self::THUMBNAIL_DONE,
        'Generate a thumbnail from the source image', async: true, transport: 'image_thumbnail')]
    public const THUMBNAIL = 'thumbnail';

    #[Transition(self::DESCRIPTION_PENDING, self::DESCRIPTION_DONE,
        'Describe the source image', async: true, transport: 'image_description')]
    public const DESCRIBE = 'describe';

    #[Transition([self::THUMBNAIL_DONE, self::DESCRIPTION_DONE], self::COMPLETED,
        'Send both results to the completion webhook', next: [], async: true, transport: 'image_webhook')]
    public const COMPLETE = 'complete';

    public function __construct(private HttpClientInterface $http)
    {
    }

    // The executable and tests replace these integrations with MockHttpClient.
    #[AsTransitionListener(transition: self::DOWNLOAD)]
    public function download(TransitionEvent $event): void
    {
        $event->getSubject()->download = $this->http->request('GET', 'https://example.invalid/source')->getContent();
    }

    #[AsTransitionListener(transition: self::THUMBNAIL)]
    public function thumbnail(TransitionEvent $event): void
    {
        $image = $event->getSubject();
        $image->thumbnail = $this->http->request('POST', 'https://example.invalid/thumbnail', [
            'body' => $image->download,
        ])->toArray()['url'];
    }

    #[AsTransitionListener(transition: self::DESCRIBE)]
    public function describe(TransitionEvent $event): void
    {
        $image = $event->getSubject();
        $image->description = $this->http->request('POST', 'https://example.invalid/description', [
            'body' => $image->download,
        ])->toArray()['description'];
    }

    #[AsTransitionListener(transition: self::COMPLETE)]
    public function complete(TransitionEvent $event): void
    {
        $image = $event->getSubject();
        $this->http->request('POST', 'https://example.invalid/webhook', [
            // id identifies one processing run. The receiver must honor this key.
            'headers' => ['Idempotency-Key' => 'image:'.$image->id],
            'json' => ['id' => $image->id, 'thumbnail' => $image->thumbnail, 'description' => $image->description],
        ])->getContent();
    }
}
