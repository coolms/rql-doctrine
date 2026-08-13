<?php

declare(strict_types=1);

namespace CoolMS\RqlDoctrine\Tests\DQL;

use CoolMS\RqlDoctrine\DQL\JsonbGetTextFunction;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Platforms\AbstractPlatform;
use Doctrine\DBAL\Platforms\MariaDBPlatform;
use Doctrine\DBAL\Platforms\MySQLPlatform;
use Doctrine\DBAL\Platforms\PostgreSQLPlatform;
use Doctrine\DBAL\Platforms\SQLitePlatform;
use Doctrine\ORM\Query\AST\InputParameter;
use Doctrine\ORM\Query\AST\Literal;
use Doctrine\ORM\Query\AST\Node;
use Doctrine\ORM\Query\SqlWalker;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use ReflectionProperty;
use RuntimeException;

final class JsonbGetTextFunctionTest extends TestCase
{
    #[Test]
    public function postgresEmitsArrowArrowOperator(): void
    {
        $sql = $this->emit(
            new PostgreSQLPlatform(),
            new FakeRawNode('n0_.extras'),
            new Literal(Literal::STRING, 'generatedAt'),
        );

        self::assertSame("(n0_.extras->>'generatedAt')", $sql);
    }

    #[Test]
    public function mysqlEmitsUnquoteExtractWithConcatPath(): void
    {
        $sql = $this->emit(
            new MySQLPlatform(),
            new FakeRawNode('n0_.extras'),
            new Literal(Literal::STRING, 'generatedAt'),
        );

        // JSON_UNQUOTE strips the surrounding double-quotes JSON_EXTRACT wraps
        // string scalars in, so the value matches the Postgres `->>` form.
        self::assertSame(
            "JSON_UNQUOTE(JSON_EXTRACT(n0_.extras, CONCAT('$.', 'generatedAt')))",
            $sql,
        );
    }

    #[Test]
    public function mariadbRoutesThroughMysqlBranch(): void
    {
        // MariaDBPlatform extends MySQLPlatform in DBAL 4.x, so the instanceof
        // check picks the MySQL branch -- no separate emitter needed.
        $sql = $this->emit(
            new MariaDBPlatform(),
            new FakeRawNode('n0_.extras'),
            new Literal(Literal::STRING, 'generatedAt'),
        );

        self::assertSame(
            "JSON_UNQUOTE(JSON_EXTRACT(n0_.extras, CONCAT('$.', 'generatedAt')))",
            $sql,
        );
    }

    #[Test]
    public function sqliteEmitsJsonExtractWithPipeConcatPath(): void
    {
        $sql = $this->emit(
            new SQLitePlatform(),
            new FakeRawNode('n0_.extras'),
            new Literal(Literal::STRING, 'generatedAt'),
        );

        self::assertSame("json_extract(n0_.extras, '$.' || 'generatedAt')", $sql);
    }

    #[Test]
    public function postgresSupportsParametricKey(): void
    {
        // The key argument flows verbatim through dispatch, so a bound parameter
        // (`:keyParam`) lowers to its placeholder string -- the CONCAT / `||`
        // path-building on MySQL/SQLite works the same way.
        $sql = $this->emit(
            new PostgreSQLPlatform(),
            new FakeRawNode('n0_.extras'),
            new InputParameter(':keyParam'),
        );

        self::assertSame('(n0_.extras->>?)', $sql);
    }

    #[Test]
    public function unknownPlatformThrowsRuntimeException(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('JSONB_GET_TEXT is not implemented for platform');

        // Mock AbstractPlatform directly -- the fallback only needs the
        // platform's class name for its error message, so no behaviour is
        // exercised on the mock and we avoid stubbing every abstract method.
        $platform = $this->createStub(AbstractPlatform::class);

        $this->emit($platform, new FakeRawNode('n0_.extras'), new Literal(Literal::STRING, 'generatedAt'));
    }

    private function emit(AbstractPlatform $platform, Node $haystack, Node $key): string
    {
        $function = new JsonbGetTextFunction('JSONB_GET_TEXT');
        new ReflectionProperty(JsonbGetTextFunction::class, 'haystack')->setValue($function, $haystack);
        new ReflectionProperty(JsonbGetTextFunction::class, 'key')->setValue($function, $key);

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
        $walker->method('walkInputParameter')->willReturn('?');

        return $function->getSql($walker);
    }
}
