<?php

declare(strict_types=1);

namespace CoolMS\Rql\Doctrine\Tests\DQL;

use CoolMS\Rql\Doctrine\DQL\JsonbExistsFunction;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Platforms\AbstractPlatform;
use Doctrine\DBAL\Platforms\MariaDBPlatform;
use Doctrine\DBAL\Platforms\MySQLPlatform;
use Doctrine\DBAL\Platforms\PostgreSQLPlatform;
use Doctrine\DBAL\Platforms\SQLitePlatform;
use Doctrine\ORM\Query\AST\Literal;
use Doctrine\ORM\Query\AST\Node;
use Doctrine\ORM\Query\SqlWalker;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use ReflectionProperty;

final class JsonbExistsFunctionTest extends TestCase
{
    #[Test]
    public function postgresEmitsJsonbExistsFunctionForm(): void
    {
        $sql = $this->emit(
            new PostgreSQLPlatform(),
            new FakeRawNode('n0_.extras'),
            new Literal(Literal::STRING, 'error'),
        );

        // Function form (`jsonb_exists`) -- not the `?` operator -- to avoid
        // DBAL's SQL parser misreading the `?` as a positional placeholder.
        self::assertSame("jsonb_exists((n0_.extras)::jsonb, 'error')", $sql);
    }

    #[Test]
    public function mysqlEmitsJsonContainsPath(): void
    {
        $sql = $this->emit(
            new MySQLPlatform(),
            new FakeRawNode('n0_.extras'),
            new Literal(Literal::STRING, 'error'),
        );

        self::assertSame("JSON_CONTAINS_PATH(n0_.extras, 'one', CONCAT('$.', 'error'))", $sql);
    }

    #[Test]
    public function mariadbRoutesThroughMysqlBranch(): void
    {
        $sql = $this->emit(
            new MariaDBPlatform(),
            new FakeRawNode('n0_.extras'),
            new Literal(Literal::STRING, 'error'),
        );

        self::assertStringStartsWith('JSON_CONTAINS_PATH(', $sql);
    }

    #[Test]
    public function sqliteEmitsJsonExtractIsNotNull(): void
    {
        $sql = $this->emit(
            new SQLitePlatform(),
            new FakeRawNode('n0_.extras'),
            new Literal(Literal::STRING, 'error'),
        );

        self::assertSame("(json_extract(n0_.extras, '$.' || 'error') IS NOT NULL)", $sql);
    }

    private function emit(AbstractPlatform $platform, Node $haystack, Node $key): string
    {
        $function = new JsonbExistsFunction('JSONB_EXISTS');
        new ReflectionProperty(JsonbExistsFunction::class, 'haystack')->setValue($function, $haystack);
        new ReflectionProperty(JsonbExistsFunction::class, 'key')->setValue($function, $key);

        $connection = $this->createStub(Connection::class);
        $connection->method('getDatabasePlatform')->willReturn($platform);

        $walker = $this->createStub(SqlWalker::class);
        $walker->method('getConnection')->willReturn($connection);
        $walker->method('walkLiteral')->willReturnCallback(static function (Literal $l): string {
            if (Literal::STRING === $l->type) {
                /** @var string $v */
                $v = $l->value;

                return "'" . str_replace("'", "''", $v) . "'";
            }

            return (string) $l->value;
        });

        return $function->getSql($walker);
    }
}
