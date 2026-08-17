<?php

declare(strict_types=1);

namespace CoolMS\Rql\Doctrine\Tests\DependencyInjection;

use CoolMS\Rql\Doctrine\DependencyInjection\RqlDoctrineExtension;
use CoolMS\Rql\Doctrine\DoctrineRqlVisitor;
use CoolMS\Rql\Doctrine\DQL\JsonbCastFunction;
use CoolMS\Rql\Doctrine\DQL\SqliteJsonExtractFunction;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Extension\ExtensionInterface;

#[CoversClass(RqlDoctrineExtension::class)]
final class RqlDoctrineExtensionTest extends TestCase
{
    public function testItRegistersTheVisitorServices(): void
    {
        $container = new ContainerBuilder();
        new RqlDoctrineExtension()->load([], $container);

        self::assertTrue($container->hasDefinition(DoctrineRqlVisitor::class));
    }

    public function testItPrependsTheDqlFunctionsOntoTheDefaultManager(): void
    {
        $container = $this->containerWithDoctrine();

        new RqlDoctrineExtension()->prepend($container);

        $orm = $this->ormConfig($container);
        self::assertArrayHasKey('default', $orm['entity_managers']);

        $functions = $orm['entity_managers']['default']['dql']['string_functions'];
        self::assertSame(JsonbCastFunction::class, $functions['JSONB_CAST']);
        self::assertSame(SqliteJsonExtractFunction::class, $functions['SQLITE_JSON_EXTRACT']);
        self::assertCount(9, $functions);
    }

    /**
     * An application is free to name its managers, and Doctrine registers DQL
     * functions per manager. Prepending onto "default" when the application
     * calls its manager something else produces no error at all -- the
     * functions are simply unknown at query time.
     */
    public function testItHonoursConfiguredEntityManagerNames(): void
    {
        $container = $this->containerWithDoctrine();
        $container->prependExtensionConfig('rql_doctrine', ['entity_managers' => ['central', 'reporting']]);

        new RqlDoctrineExtension()->prepend($container);

        $managers = $this->ormConfig($container)['entity_managers'];
        self::assertSame(['central', 'reporting'], array_keys($managers));
        self::assertArrayNotHasKey('default', $managers);
    }

    public function testRegistrationCanBeTurnedOff(): void
    {
        $container = $this->containerWithDoctrine();
        $container->prependExtensionConfig('rql_doctrine', ['register_dql_functions' => false]);

        new RqlDoctrineExtension()->prepend($container);

        self::assertSame([], $container->getExtensionConfig('doctrine'));
    }

    /**
     * The bundle must not assume DoctrineBundle is installed: an application
     * could pull this in for the AST-walking alone.
     */
    public function testItDoesNothingWithoutDoctrineBundle(): void
    {
        $container = new ContainerBuilder();

        new RqlDoctrineExtension()->prepend($container);

        self::assertSame([], $container->getExtensionConfig('doctrine'));
    }

    private function containerWithDoctrine(): ContainerBuilder
    {
        $container = new ContainerBuilder();
        $container->registerExtension(new class implements ExtensionInterface {
            public function load(array $configs, ContainerBuilder $container): void
            {
            }

            public function getNamespace(): string
            {
                return '';
            }

            public function getXsdValidationBasePath(): false
            {
                return false;
            }

            public function getAlias(): string
            {
                return 'doctrine';
            }
        });

        return $container;
    }

    /**
     * @return array{entity_managers: array<string, array{dql: array{string_functions: array<string, string>}}>}
     */
    private function ormConfig(ContainerBuilder $container): array
    {
        $configs = $container->getExtensionConfig('doctrine');
        self::assertNotSame([], $configs, 'nothing was prepended onto the doctrine extension');

        return $configs[0]['orm'];
    }
}
