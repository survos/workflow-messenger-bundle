<?php

declare(strict_types=1);

namespace Survos\WorkflowExample\ImageProcessing;

use Survos\WorkflowExtrasBundle\Service\NextTransitionRunner;
use Survos\WorkflowExtrasBundle\SurvosWorkflowExtrasBundle;
use Survos\WorkflowMessengerBundle\Service\TransitionDispatcher;
use Survos\WorkflowMessengerBundle\SurvosWorkflowMessengerBundle;
use Symfony\Component\DependencyInjection\Kernel\AbstractKernel;
use Symfony\Component\DependencyInjection\Kernel\KernelTrait;
use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Contracts\HttpClient\HttpClientInterface;

final class ImageKernel extends AbstractKernel
{
    use KernelTrait;

    public const TRANSPORTS = ['image_download', 'image_thumbnail', 'image_description', 'image_webhook'];

    public function __construct(private string $directory)
    {
        parent::__construct('dev', true);
    }

    public function getProjectDir(): string
    {
        return dirname(__DIR__, 2);
    }

    public function getCacheDir(): string
    {
        return $this->directory.'/cache';
    }

    public function getLogDir(): string
    {
        return $this->directory.'/log';
    }

    public function registerBundles(): iterable
    {
        yield new SurvosWorkflowMessengerBundle();
        yield new SurvosWorkflowExtrasBundle();
    }

    private function configureContainer(ContainerConfigurator $container): void
    {
        $container->extension('workflow', []);
        $transports = [];
        foreach (self::TRANSPORTS as $name) {
            $transports[$name] = ['dsn' => 'in-memory://', 'options' => ['serialize' => true]];
        }
        $container->extension('messenger', ['transports' => $transports]);
        $container->extension('survos_workflow_messenger', ['subject_store' => ImageStore::class]);
        $services = $container->services()->defaults()->autowire()->autoconfigure();
        $services->set(ImageStore::class)->arg('$directory', $this->directory.'/subjects')->public();
        $services->set(ImageWorkflow::class);
        $services->set(HttpClientInterface::class, MockHttpClient::class)->public();
        foreach ([TransitionDispatcher::class, NextTransitionRunner::class, 'workflow.'.ImageWorkflow::NAME, 'messenger.default_bus', ...array_map(static fn (string $name) => 'messenger.transport.'.$name, self::TRANSPORTS)] as $id) {
            $services->alias('example.'.$id, $id)->public();
        }
    }
}
