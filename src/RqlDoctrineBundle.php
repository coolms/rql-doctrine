<?php

declare(strict_types=1);

namespace CoolMS\Rql\Doctrine;

use CoolMS\Rql\Doctrine\DependencyInjection\Compiler\PredicateContributorPass;
use CoolMS\Rql\Doctrine\DependencyInjection\RqlDoctrineExtension;
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

    // Narrower than the parent's `?ExtensionInterface`: this bundle always has
    // an extension, and saying so spares every caller a null check.
    public function getContainerExtension(): ExtensionInterface
    {
        return new RqlDoctrineExtension();
    }
}
