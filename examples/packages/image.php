<?php

declare(strict_types=1);

use Survos\WorkflowExample\ImageProcessing\Image;
use Survos\WorkflowExample\ImageProcessing\ImageKernel;
use Survos\WorkflowExample\ImageProcessing\ImageStore;
use Survos\WorkflowExample\ImageProcessing\ImageWorkflow;
use Survos\WorkflowExample\RunQueue;
use Survos\WorkflowExtrasBundle\Service\NextTransitionRunner;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;
use Symfony\Contracts\HttpClient\HttpClientInterface;

require __DIR__.'/vendor/autoload.php';

$directory = sys_get_temp_dir().'/image-workflow-demo-'.bin2hex(random_bytes(6));
$kernel = new ImageKernel($directory);
try {
    $kernel->boot();
    $container = $kernel->getContainer();
    $container->set(HttpClientInterface::class, new MockHttpClient(static function (string $method, string $url, array $options): MockResponse {
        return new MockResponse(match (parse_url($url, PHP_URL_PATH)) {
            '/source' => 'offline image fixture',
            '/thumbnail' => '{"url":"https://example.invalid/thumb.jpg"}',
            '/description' => '{"description":"A museum photograph"}',
            '/webhook' => (static function () use ($options): string {
                printf("Completion webhook: %s\n", $options['body']);

                return '';
            })(),
            default => throw new LogicException('Unexpected example URL: '.$url),
        });
    }));
    $store = $container->get(ImageStore::class);
    $image = new Image('demo-run');
    $reference = $store->reference($image);
    $store->save($reference, $image);
    $workflow = $container->get('example.workflow.'.ImageWorkflow::NAME);
    $container->get('example.'.NextTransitionRunner::class)->start($workflow, $image);
    foreach (ImageKernel::TRANSPORTS as $name) {
        RunQueue::once($name, $container->get('example.messenger.transport.'.$name), $container->get('example.messenger.default_bus'));
        printf("%s → %s\n", $name, json_encode($store->load($reference)->marking, JSON_THROW_ON_ERROR));
    }
    if ([ImageWorkflow::COMPLETED => 1] !== $store->load($reference)->marking) {
        throw new RuntimeException('Image workflow did not complete.');
    }
} finally {
    $kernel->shutdown();
    (new Filesystem())->remove($directory);
}
