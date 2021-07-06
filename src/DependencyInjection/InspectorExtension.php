<?php


namespace Inspector\Symfony\Bundle\DependencyInjection;

use Inspector\Inspector;
use Inspector\Symfony\Bundle\Inspectable\Twig\InspectableTwigExtension;
use Inspector\Symfony\Bundle\Listeners\ConsoleEventsSubscriber;
use Inspector\Symfony\Bundle\Listeners\KernelEventsSubscriber;
use Symfony\Component\Config\FileLocator;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Definition;
use Symfony\Component\DependencyInjection\Loader\YamlFileLoader;
use Symfony\Component\DependencyInjection\Reference;
use Symfony\Component\HttpKernel\DependencyInjection\Extension;

class InspectorExtension extends Extension
{
    /**
     * Current version of the bundle.
     */
    const VERSION = '1.0.2';

    /**
     * Loads a specific configuration.
     *
     * @throws \InvalidArgumentException|\Exception When provided tag is not defined in this extension
     */
    public function load(array $configs, ContainerBuilder $container)
    {
        $configuration = $this->getConfiguration($configs, $container);
        $config = $this->processConfiguration($configuration, $configs);

        $container->setParameter('inspector.configuration', $config);

        if(true !== $config['enabled'] || empty($config['ingestion_key'])) {
            return;
        }

        // Inspector configuration
        $inspectorConfigDefinition = new Definition(\Inspector\Configuration::class, [$config['ingestion_key']]);
        $inspectorConfigDefinition->setPublic(false);
        $inspectorConfigDefinition->addMethodCall('setEnabled', [$config['enabled']]);
        $inspectorConfigDefinition->addMethodCall('setUrl', [$config['url']]);
        $inspectorConfigDefinition->addMethodCall('setTransport', [$config['transport']]);
        $inspectorConfigDefinition->addMethodCall('serverSamplingRatio', [$config['server_sampling_ratio']]);
        $inspectorConfigDefinition->addMethodCall('setVersion', [self::VERSION]);

        $container->setDefinition('inspector.configuration.internal', $inspectorConfigDefinition);

        // Inspector service itself
        $inspectorDefinition = new Definition(Inspector::class, [$inspectorConfigDefinition]);
        $inspectorDefinition->setPublic(true);

        $container->setDefinition('inspector', $inspectorDefinition);

        // Kernel events subscriber: request, response etc.
        $kernelEventsSubscriberDefinition = new Definition(KernelEventsSubscriber::class, [
            new Reference('inspector'),
            new Reference('router'),
            new Reference('security.helper'),
            $config['ignore_routes']
        ]);
        $kernelEventsSubscriberDefinition->setPublic(false)->addTag('kernel.event_subscriber');

        $container->setDefinition(KernelEventsSubscriber::class, $kernelEventsSubscriberDefinition);

        // Console events subscriber
        $consoleEventsSubscriberDefinition = new Definition(ConsoleEventsSubscriber::class, [
            new Reference('inspector'),
            $config['ignore_commands'],
        ]);
        $consoleEventsSubscriberDefinition->setPublic(false)->addTag('kernel.event_subscriber');

        $container->setDefinition(ConsoleEventsSubscriber::class, $consoleEventsSubscriberDefinition);

        if (true === $config['templates']) {
            $inspectableTwigExtensionDefinition = new Definition(InspectableTwigExtension::class, [
                new Reference('inspector'),
            ]);
            $inspectableTwigExtensionDefinition->addTag('twig.extension');

            $container->setDefinition(InspectableTwigExtension::class, $inspectableTwigExtensionDefinition);
        }

        $loader = new YamlFileLoader($container, new FileLocator(__DIR__.'/../Resources/config'));
        $loader->load('services.yml');
    }
}
