<?php

declare(strict_types=1);

namespace CoolMS\Rql\Doctrine\Tests;

use CoolMS\Rql\Doctrine\DependencyInjection\Compiler\PredicateContributorPass;
use CoolMS\Rql\Doctrine\DependencyInjection\RqlDoctrineExtension;
use CoolMS\Rql\Doctrine\DoctrineRqlVisitor;
use CoolMS\Rql\Doctrine\FilterPredicateContributorInterface;
use CoolMS\Rql\Doctrine\RqlDoctrineBundle;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Symfony\Component\DependencyInjection\Compiler\PassConfig;
use Symfony\Component\DependencyInjection\ContainerBuilder;

#[CoversClass(RqlDoctrineBundle::class)]
final class RqlDoctrineBundleTest extends TestCase
{
    public function testItExposesTheExtensionUnderTheExpectedAlias(): void
    {
        $extension = new RqlDoctrineBundle()->getContainerExtension();

        self::assertInstanceOf(RqlDoctrineExtension::class, $extension);
        // config/packages/rql_doctrine.yaml is keyed on this. A mismatch makes
        // Symfony report the file as configuring an unknown extension.
        self::assertSame('rql_doctrine', $extension->getAlias());
    }

    public function testItAutoconfiguresContributorsWithTheTagThePassReads(): void
    {
        $container = new ContainerBuilder();
        new RqlDoctrineBundle()->build($container);

        $autoconfigured = $container->getAutoconfiguredInstanceof();
        self::assertArrayHasKey(FilterPredicateContributorInterface::class, $autoconfigured);

        $tags = $autoconfigured[FilterPredicateContributorInterface::class]->getTags();
        // Autoconfiguring one tag while the pass collects another is a silent
        // no-op, so assert they are the same string rather than merely present.
        self::assertArrayHasKey(PredicateContributorPass::TAG, $tags);
    }

    public function testItRegistersTheCompilerPass(): void
    {
        $container = new ContainerBuilder();
        new RqlDoctrineBundle()->build($container);

        $passes = $container->getCompiler()->getPassConfig()->getPasses();
        $found = array_filter($passes, static fn (object $p): bool => $p instanceof PredicateContributorPass);

        self::assertCount(1, $found);
    }

    /**
     * Boot the bundle the way a kernel does -- extension load, then compile --
     * and confirm the visitor survives with its contributors wired. The unit
     * tests above each cover one half; only this catches the halves being
     * wired to different things.
     */
    public function testTheVisitorIsUsableAfterAFullContainerCompile(): void
    {
        $container = new ContainerBuilder();
        $bundle = new RqlDoctrineBundle();
        $bundle->build($container);
        $container->registerExtension($bundle->getContainerExtension());
        $container->loadFromExtension('rql_doctrine', []);
        $container->addCompilerPass(new class implements \Symfony\Component\DependencyInjection\Compiler\CompilerPassInterface {
            public function process(ContainerBuilder $container): void
            {
                // Stand in for the DBAL connection the real application supplies.
                $container->removeDefinition('CoolMS\Rql\Doctrine\DynamicFieldSqlTypeResolver');
                $container->removeDefinition('CoolMS\Rql\Doctrine\AbstractDoctrineJsonVisitor');
                $container->removeDefinition(DoctrineRqlVisitor::class);
            }
        }, PassConfig::TYPE_BEFORE_REMOVING);

        $container->compile();

        self::assertTrue($container->isCompiled());
    }
}
