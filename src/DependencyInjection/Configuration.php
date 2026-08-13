<?php

declare(strict_types=1);

namespace CoolMS\RqlDoctrine\DependencyInjection;

use Symfony\Component\Config\Definition\Builder\TreeBuilder;
use Symfony\Component\Config\Definition\ConfigurationInterface;

final class Configuration implements ConfigurationInterface
{
    public function getConfigTreeBuilder(): TreeBuilder
    {
        $treeBuilder = new TreeBuilder('rql_doctrine');

        $treeBuilder->getRootNode()
            ->children()
                // Doctrine registers DQL functions per entity manager, and an
                // application is free to name its managers anything. Defaulting
                // to 'default' covers the single-manager case; an application
                // with named managers lists them here.
                ->arrayNode('entity_managers')
                    ->info('Entity managers to register the JSON DQL functions on.')
                    ->scalarPrototype()->end()
                    ->defaultValue(['default'])
                ->end()
                ->booleanNode('register_dql_functions')
                    ->info('Set false to register the DQL functions yourself.')
                    ->defaultTrue()
                ->end()
            ->end();

        return $treeBuilder;
    }
}
