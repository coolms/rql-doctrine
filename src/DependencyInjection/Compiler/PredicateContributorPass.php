<?php

declare(strict_types=1);

namespace CoolMS\RqlDoctrine\DependencyInjection\Compiler;

use CoolMS\RqlDoctrine\DoctrineRqlVisitor;
use Symfony\Component\DependencyInjection\Argument\TaggedIteratorArgument;
use Symfony\Component\DependencyInjection\Compiler\CompilerPassInterface;
use Symfony\Component\DependencyInjection\ContainerBuilder;

/**
 * Feeds the tagged predicate contributors into the visitor.
 *
 * A compiler pass rather than a plain service argument, because an application
 * whose `App\` glob re-registers the visitor would silently drop wiring an
 * extension had done: the glob loads after bundle extensions and replaces the
 * definition. A pass runs once every definition exists, so it survives.
 */
final class PredicateContributorPass implements CompilerPassInterface
{
    public const string TAG = 'coolms.rql_doctrine.filter_predicate_contributor';

    public function process(ContainerBuilder $container): void
    {
        if (!$container->hasDefinition(DoctrineRqlVisitor::class)) {
            return;
        }

        // The visitor is the one funnel every repository's RQL passes through,
        // so a contributing module needs no constructor change in any
        // repository it wants to extend.
        $container->findDefinition(DoctrineRqlVisitor::class)
            ->setArgument('$predicateContributors', new TaggedIteratorArgument(self::TAG));
    }
}
