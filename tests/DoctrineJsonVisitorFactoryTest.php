<?php

declare(strict_types=1);

namespace CoolMS\RqlDoctrine\Tests;

use CoolMS\RqlDoctrine\DoctrineJsonVisitorFactory;
use CoolMS\RqlDoctrine\DynamicFieldSqlTypeResolver;
use CoolMS\RqlDoctrine\Platform\MariaDBDoctrineJsonVisitor;
use CoolMS\RqlDoctrine\Platform\MySQLDoctrineJsonVisitor;
use CoolMS\RqlDoctrine\Platform\OracleDoctrineJsonVisitor;
use CoolMS\RqlDoctrine\Platform\PostgreSQLDoctrineJsonVisitor;
use CoolMS\RqlDoctrine\Platform\SQLiteDoctrineJsonVisitor;
use CoolMS\RqlDoctrine\Platform\SQLServerDoctrineJsonVisitor;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Platforms\AbstractPlatform;
use Doctrine\DBAL\Platforms\MariaDBPlatform;
use Doctrine\DBAL\Platforms\MySQLPlatform;
use Doctrine\DBAL\Platforms\OraclePlatform;
use Doctrine\DBAL\Platforms\PostgreSQLPlatform;
use Doctrine\DBAL\Platforms\SQLitePlatform;
use Doctrine\DBAL\Platforms\SQLServerPlatform;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use RuntimeException;

#[CoversClass(DoctrineJsonVisitorFactory::class)]
final class DoctrineJsonVisitorFactoryTest extends TestCase
{
    /**
     * @return iterable<string, array{AbstractPlatform, class-string}>
     */
    public static function platforms(): iterable
    {
        yield 'postgres' => [new PostgreSQLPlatform(), PostgreSQLDoctrineJsonVisitor::class];
        yield 'mysql' => [new MySQLPlatform(), MySQLDoctrineJsonVisitor::class];
        yield 'mariadb' => [new MariaDBPlatform(), MariaDBDoctrineJsonVisitor::class];
        yield 'sqlite' => [new SQLitePlatform(), SQLiteDoctrineJsonVisitor::class];
        yield 'sqlserver' => [new SQLServerPlatform(), SQLServerDoctrineJsonVisitor::class];
        yield 'oracle' => [new OraclePlatform(), OracleDoctrineJsonVisitor::class];
    }

    /**
     * @param class-string $expected
     */
    #[DataProvider('platforms')]
    public function testItSelectsTheVisitorForTheConnectedPlatform(
        AbstractPlatform $platform,
        string $expected,
    ): void {
        $visitor = DoctrineJsonVisitorFactory::create(
            $this->connectionFor($platform),
            $this->typeResolver(),
        );

        self::assertInstanceOf($expected, $visitor);
    }

    /**
     * MariaDBPlatform extends MySQLPlatform, so a match arm ordered the other
     * way round would hand MariaDB the MySQL visitor and still pass every
     * "is an AbstractDoctrineJsonVisitor" assertion. The two emit different
     * JSON SQL, so this is pinned separately from the provider above.
     */
    public function testMariaDbIsNotServedTheMySqlVisitor(): void
    {
        $visitor = DoctrineJsonVisitorFactory::create(
            $this->connectionFor(new MariaDBPlatform()),
            $this->typeResolver(),
        );

        self::assertNotInstanceOf(MySQLDoctrineJsonVisitor::class, $visitor);
    }

    public function testAnUnsupportedPlatformThrowsAndNamesIt(): void
    {
        // A double, not an anonymous subclass: AbstractPlatform has 18 abstract
        // methods and implementing them here would say nothing about the
        // factory.
        $platform = $this->createStub(AbstractPlatform::class);

        $this->expectException(RuntimeException::class);
        // The class name has to be in the message: "unsupported platform" alone
        // sends whoever hits this looking through config for which one it was.
        $this->expectExceptionMessageMatches('/' . preg_quote($platform::class, '/') . '/');

        DoctrineJsonVisitorFactory::create($this->connectionFor($platform), $this->typeResolver());
    }

    /**
     * Stubs rather than mocks throughout: nothing here asserts on a call, only
     * on what the factory returns, and PHPUnit 12 raises a notice for a mock
     * with no configured expectations.
     */
    private function connectionFor(AbstractPlatform $platform): Connection
    {
        $connection = $this->createStub(Connection::class);
        $connection->method('getDatabasePlatform')->willReturn($platform);

        return $connection;
    }

    private function typeResolver(): DynamicFieldSqlTypeResolver
    {
        return new DynamicFieldSqlTypeResolver($this->createStub(Connection::class));
    }
}
