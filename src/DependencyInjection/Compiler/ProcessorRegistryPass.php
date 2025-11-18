<?php

namespace App\DependencyInjection\Compiler;

use Symfony\Component\DependencyInjection\Compiler\CompilerPassInterface;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Reference;
use App\Service\QueueProcessor\ProcessorRegistry;

class ProcessorRegistryPass implements CompilerPassInterface
{
    public function process(ContainerBuilder $container): void
    {
        if (!$container->has(ProcessorRegistry::class)) {
            return;
        }

        $registry = $container->findDefinition(ProcessorRegistry::class);
        $processors = $container->findTaggedServiceIds('app.queue_processor');

        foreach (array_keys($processors) as $id) {
            $registry->addMethodCall('register', [new Reference($id)]);
        }
    }
}
