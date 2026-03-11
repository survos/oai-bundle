<?php

declare(strict_types=1);

namespace Survos\OaiBundle\DI;

use Survos\OaiBundle\Service\OaiXmlBuilder;
use Symfony\Component\DependencyInjection\Compiler\CompilerPassInterface;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Reference;

/**
 * Wires the single tagged OaiDataProviderInterface implementation into OaiXmlBuilder.
 *
 * If no provider is registered the server-side services are removed, leaving
 * the harvester command fully functional as a standalone tool.
 */
final class OaiDataProviderPass implements CompilerPassInterface
{
    public function process(ContainerBuilder $container): void
    {
        $tagged = $container->findTaggedServiceIds('survos_oai.data_provider');

        if (empty($tagged)) {
            // No provider registered — remove server-side services gracefully
            foreach ([OaiXmlBuilder::class, \Survos\OaiBundle\Controller\OaiController::class] as $id) {
                if ($container->hasDefinition($id)) {
                    $container->removeDefinition($id);
                }
            }
            return;
        }

        if (count($tagged) > 1) {
            throw new \LogicException(sprintf(
                'Multiple services tagged "survos_oai.data_provider" found (%s). '
                . 'Only one OaiDataProviderInterface implementation may be registered.',
                implode(', ', array_keys($tagged)),
            ));
        }

        $providerId = array_key_first($tagged);

        $container->getDefinition(OaiXmlBuilder::class)
            ->replaceArgument('$provider', new Reference($providerId));
    }
}
