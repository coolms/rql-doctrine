<?php

declare(strict_types=1);

namespace CoolMS\Rql\Doctrine\Tests\DQL;

use CoolMS\Rql\Doctrine\DQL\JsonbContainsFunction;
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
use PDO;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use ReflectionProperty;
use RuntimeException;

final class JsonbContainsFunctionTest extends TestCase
{
    /**
     * @return iterable<string, array{string, list<int>}>
     */
    public static function flatObjectContainmentCases(): iterable
    {
        yield 'boolean true marker' => ['{"mediaAsset": true}', [1]];
        yield 'boolean false marker' => ['{"mediaAsset": false}', [2]];
        yield 'string marker' => ['{"contentType": "blog"}', [4]];
        yield 'multi-key marker' => ['{"contentType": "blog", "hasPublishedVariant": true}', [4]];
        yield 'absent key never matches' => ['{"contentType": "news"}', []];
        yield 'array needle yields no match' => ['[{"vfsNodeId": "abc"}]', []];
    }

    #[Test]
    public function postgresEmitsJsonbContainsOperator(): void
    {
        $sql = $this->emit(
            new PostgreSQLPlatform(),
            new FakeRawNode('n0_.extras'),
            new Literal(Literal::STRING, '{"mediaAsset": true}'),
        );

        self::assertSame("((n0_.extras)::jsonb @> ('{\"mediaAsset\": true}')::jsonb)", $sql);
    }

    #[Test]
    public function mysqlEmitsJsonContains(): void
    {
        $sql = $this->emit(
            new MySQLPlatform(),
            new FakeRawNode('n0_.extras'),
            new Literal(Literal::STRING, '{"mediaAsset": true}'),
        );

        self::assertSame("JSON_CONTAINS(n0_.extras, '{\"mediaAsset\": true}')", $sql);
    }

    #[Test]
    public function mariadbRoutesThroughMysqlBranch(): void
    {
        // MariaDBPlatform extends MySQLPlatform in DBAL 4.x, so the instanceof
        // check picks the MySQL branch -- no separate emitter needed.
        $sql = $this->emit(
            new MariaDBPlatform(),
            new FakeRawNode('n0_.extras'),
            new Literal(Literal::STRING, '{"mediaAsset": true}'),
        );

        self::assertStringStartsWith('JSON_CONTAINS(', $sql);
    }

    #[Test]
    public function sqliteLiftsFlatObjectLiteralToConjunction(): void
    {
        $sql = $this->emit(
            new SQLitePlatform(),
            new FakeRawNode('n0_.extras'),
            new Literal(Literal::STRING, '{"mediaAsset": true}'),
        );

        self::assertSame("(json_extract(n0_.extras, '$.mediaAsset') = 1)", $sql);
    }

    #[Test]
    public function sqliteHandlesMultipleKeysWithAnd(): void
    {
        $sql = $this->emit(
            new SQLitePlatform(),
            new FakeRawNode('n0_.extras'),
            new Literal(Literal::STRING, '{"status": "ready", "weight": 42}'),
        );

        self::assertSame(
            "(json_extract(n0_.extras, '$.status') = 'ready' AND json_extract(n0_.extras, '$.weight') = 42)",
            $sql,
        );
    }

    #[Test]
    public function sqliteLowersParameterNeedleToRuntimeContainment(): void
    {
        // A bound parameter's value is unknown at compile time, so SQLite lowers
        // to a runtime `json_each` containment check instead of throwing. This is
        // what makes Media/Content's `:marker` queries (incl. the DYNAMIC
        // content-type marker, which cannot be inlined safely) portable to SQLite.
        $sql = $this->emit(
            new SQLitePlatform(),
            new FakeRawNode('n0_.extras'),
            new InputParameter(':needle'),
        );

        self::assertSame(
            '((SELECT COUNT(*) FROM json_each(?) AS __je '
            . "WHERE json_extract(n0_.extras, '\$.' || __je.key) IS NOT __je.value) = 0)",
            $sql,
        );
    }

    /**
     * Execute the function's REAL emitted SQL against a seeded in-memory SQLite
     * DB so the runtime `json_each` lowering is proven to match Postgres `@>`
     * containment semantics for flat objects -- not just asserted as a string.
     *
     * @param list<int> $expectedIds
     */
    #[Test]
    #[DataProvider('flatObjectContainmentCases')]
    public function sqliteRuntimeContainmentMatchesFlatObjects(string $needle, array $expectedIds): void
    {
        $predicate = $this->emit(
            new SQLitePlatform(),
            new FakeRawNode('t.extras'),
            new InputParameter(':needle'),
        );

        $pdo = new PDO('sqlite::memory:');
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $pdo->exec('CREATE TABLE t (id INTEGER PRIMARY KEY, extras TEXT)');
        $seed = $pdo->prepare('INSERT INTO t (id, extras) VALUES (?, ?)');
        $seed->execute([1, '{"mediaAsset": true, "other": 1}']);
        $seed->execute([2, '{"mediaAsset": false}']);
        $seed->execute([3, '{"other": 5}']);
        $seed->execute([4, '{"contentType": "blog", "hasPublishedVariant": true}']);
        // An ARRAY needle (Chat's attachment shape) is intentionally unsupported
        // on SQLite and must yield a defined empty result, never a false match.
        $seed->execute([5, '[{"vfsNodeId": "abc"}]']);

        $stmt = $pdo->prepare("SELECT id FROM t WHERE {$predicate} = 1 ORDER BY id");
        $stmt->execute([$needle]);
        /** @var list<string> $ids */
        $ids = $stmt->fetchAll(PDO::FETCH_COLUMN);

        self::assertSame($expectedIds, array_map('intval', $ids));
    }

    #[Test]
    public function sqliteRejectsNonObjectLiteral(): void
    {
        $this->expectException(RuntimeException::class);

        $this->emit(
            new SQLitePlatform(),
            new FakeRawNode('n0_.extras'),
            new Literal(Literal::STRING, '"not an object"'),
        );
    }

    private function emit(AbstractPlatform $platform, Node $haystack, Node $needle): string
    {
        $function = new JsonbContainsFunction('JSONB_CONTAINS');
        new ReflectionProperty(JsonbContainsFunction::class, 'haystack')->setValue($function, $haystack);
        new ReflectionProperty(JsonbContainsFunction::class, 'needle')->setValue($function, $needle);

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
        // A bound parameter lowers to a positional `?` placeholder in the SQL,
        // exactly as Doctrine's real SqlWalker emits it.
        $walker->method('walkInputParameter')->willReturn('?');

        return $function->getSql($walker);
    }
}
