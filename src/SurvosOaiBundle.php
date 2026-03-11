<?php

declare(strict_types=1);

namespace Survos\OaiBundle;

use Survos\OaiBundle\Command\OaiHarvestCommand;
use Survos\OaiBundle\Contract\OaiDataProviderInterface;
use Survos\OaiBundle\Controller\OaiController;
use Survos\OaiBundle\Service\OaiXmlBuilder;
use Symfony\Component\Config\Definition\Configurator\DefinitionConfigurator;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;
use Symfony\Component\HttpKernel\Bundle\AbstractBundle;

use function Symfony\Component\DependencyInjection\Loader\Configurator\service;

/**
 * OAI-PMH 2.0 bundle: server endpoint + harvester client.
 *
 * Configuration (config/packages/survos_oai.yaml):
 *
 *   survos_oai:
 *       page_size: 100          # records per resumption page
 *       # data_provider is auto-detected from tagged services
 */
class SurvosOaiBundle extends AbstractBundle
{
    public function configure(DefinitionConfigurator $definition): void
    {
        $definition->rootNode()
            ->children()
                ->integerNode('page_size')
                    ->defaultValue(100)
                    ->info('Number of records per resumption page.')
                ->end()
            ->end();
    }

    public function loadExtension(array $config, ContainerConfigurator $container, ContainerBuilder $builder): void
    {
        // Tag interface so apps can register their provider with a simple tag
        $builder->registerForAutoconfiguration(OaiDataProviderInterface::class)
            ->addTag('survos_oai.data_provider');

        $services = $container->services();

        // OaiXmlBuilder — wired after the provider is resolved at compile time
        // We use a factory argument '$provider' that will be filled by the
        // CompilerPass below. For simplicity, wire it as a lazy alias.
        $services->set(OaiXmlBuilder::class)
            ->args([
                '$provider' => null, // replaced by CompilerPass
                '$pageSize' => $config['page_size'],
            ])
            ->public();

        $services->set(OaiController::class)
            ->args(['$builder' => service(OaiXmlBuilder::class)])
            ->tag('controller.service_arguments')
            ->public();

        $services->set(OaiHarvestCommand::class)
            ->tag('console.command')
            ->public();
    }

    public function build(ContainerBuilder $container): void
    {
        parent::build($container);
        $container->addCompilerPass(new DI\OaiDataProviderPass());
    }

    public function getPath(): string
    {
        return \dirname(__DIR__);
    }
}
