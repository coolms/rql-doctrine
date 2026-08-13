<?php

declare(strict_types=1);

namespace CoolMS\RqlDoctrine;

use CoolMS\RqlDoctrine\DependencyInjection\Compiler\PredicateContributorPass;
use CoolMS\RqlDoctrine\DependencyInjection\RqlDoctrineExtension;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Extension\ExtensionInterface;
use Symfony\Component\HttpKernel\Bundle\Bundle;

/**
 * Wires the RQL to Doctrine translator into a Symfony application.
 *
 * Registering the bundle is enough: the visitor, the platform JSON visitor and
 * the SQL type resolver become services, and the DQL functions the JSON
 * expressions rely on are prepended to your Doctrine config.
 */
final class RqlDoctrineBundle extends Bundle
{
    public function build(ContainerBuilder $container): void
    {
        parent::build($container);

        // Implementing the interface is all a contributor has to do; the tag is
        // applied for it.
        $container->registerForAutoconfiguration(FilterPredicateContributorInterface::class)
            ->addTag(PredicateContributorPass::TAG);

        $container->addCompilerPass(new PredicateContributorPass());
    }

    public function getContainerExtension(): ?ExtensionInterface
    {
        return new RqlDoctrineExtension();
    }
}
