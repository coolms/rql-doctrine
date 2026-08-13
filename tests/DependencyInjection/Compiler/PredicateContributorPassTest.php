<?php

declare(strict_types=1);

namespace CoolMS\RqlDoctrine\Tests\DependencyInjection\Compiler;

use CoolMS\RqlDoctrine\DependencyInjection\Compiler\PredicateContributorPass;
use CoolMS\RqlDoctrine\DoctrineRqlVisitor;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Symfony\Component\DependencyInjection\Argument\TaggedIteratorArgument;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Definition;

#[CoversClass(PredicateContributorPass::class)]
final class PredicateContributorPassTest extends TestCase
{
    public function testItInjectsTheTaggedContributorsIntoTheVisitor(): void
    {
        $container = new ContainerBuilder();
        $container->setDefinition(DoctrineRqlVisitor::class, new Definition(DoctrineRqlVisitor::class));

        (new PredicateContributorPass())->process($container);

        $argument = $container->getDefinition(DoctrineRqlVisitor::class)->getArgument('$predicateContributors');

        self::assertInstanceOf(TaggedIteratorArgument::class, $argument);
        self::assertSame(PredicateContributorPass::TAG, $argument->getTag());
    }

    /**
     * The pass runs in every application that installs the bundle, including
     * one that has replaced or removed the visitor definition. Throwing there
     * would break an unrelated container build.
     */
    public function testItIsSilentWhenTheVisitorIsAbsent(): void
    {
        $container = new ContainerBuilder();

        (new PredicateContributorPass())->process($container);

        self::assertFalse($container->hasDefinition(DoctrineRqlVisitor::class));
    }

    /**
     * The tag string is what a contributing application ends up depending on,
     * whether through autoconfiguration or a manual tag. Renaming it silently
     * disconnects every contributor -- nothing errors, predicates just stop
     * being applied -- so it is pinned here as a literal.
     */
    public function testTheTagNameIsPartOfThePublicContract(): void
    {
        self::assertSame('coolms.rql_doctrine.filter_predicate_contributor', PredicateContributorPass::TAG);
    }
}
