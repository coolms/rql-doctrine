<?php

declare(strict_types=1);

namespace CoolMS\Rql\Doctrine\Tests\DQL;

use CoolMS\Rql\Doctrine\DQL\JsonArrayHasFunction;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Platforms\AbstractPlatform;
use Doctrine\DBAL\Platforms\MariaDBPlatform;
use Doctrine\DBAL\Platforms\MySQLPlatform;
use Doctrine\DBAL\Platforms\PostgreSQLPlatform;
use Doctrine\DBAL\Platforms\SQLitePlatform;
use Doctrine\ORM\Query\AST\InputParameter;
use Doctrine\ORM\Query\AST\Literal;
use Doctrine\ORM\Query\SqlWalker;
use PDO;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use ReflectionProperty;

final class JsonArrayHasFunctionTest extends TestCase
{
    /**
     * @return iterable<string, array{string, list<int>}>
     */
    public static function arrayElementCases(): iterable
    {
        yield 'matches first element' => ['abc', [1]];
        yield 'matches a non-first element' => ['def', [1]];
        yield 'matches single-element array' => ['xyz', [2]];
        yield 'no element matches' => ['zzz', []];
    }

    #[Test]
    public function postgresEmitsJsonbContainmentOfBuiltArray(): void
    {
        self::assertSame(
            "((n0_.attachments)::jsonb @> jsonb_build_array(jsonb_build_object('vfsNodeId', (?)::text)))",
            $this->emit(new PostgreSQLPlatform()),
        );
    }

    #[Test]
    public function mysqlEmitsJsonContainsOfJsonObject(): void
    {
        self::assertSame(
            "JSON_CONTAINS(n0_.attachments, JSON_OBJECT('vfsNodeId', ?))",
            $this->emit(new MySQLPlatform()),
        );
    }

    #[Test]
    public function mariadbRoutesThroughMysqlBranch(): void
    {
        self::assertStringStartsWith('JSON_CONTAINS(', $this->emit(new MariaDBPlatform()));
    }

    #[Test]
    public function sqliteEmitsExistsOverJsonEach(): void
    {
        self::assertSame(
            'EXISTS (SELECT 1 FROM json_each(n0_.attachments) AS __e '
            . "WHERE json_extract(__e.value, '\$.' || 'vfsNodeId') = ?)",
            $this->emit(new SQLitePlatform()),
        );
    }

    /**
     * Execute the function's REAL emitted SQL against a seeded in-memory SQLite
     * DB so the `json_each` array-element lowering is proven to match Postgres
     * `@>` array containment -- not merely asserted as a string.
     *
     * @param list<int> $expectedIds
     */
    #[Test]
    #[DataProvider('arrayElementCases')]
    public function sqliteRuntimeArrayContainmentMatchesElements(string $value, array $expectedIds): void
    {
        $predicate = $this->emit(new SQLitePlatform(), 'm.attachments');

        $pdo = new PDO('sqlite::memory:');
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $pdo->exec('CREATE TABLE m (id INTEGER PRIMARY KEY, attachments TEXT)');
        $seed = $pdo->prepare('INSERT INTO m (id, attachments) VALUES (?, ?)');
        $seed->execute([1, '[{"vfsNodeId": "abc", "name": "a.png"}, {"vfsNodeId": "def"}]']);
        $seed->execute([2, '[{"vfsNodeId": "xyz"}]']);
        $seed->execute([3, '[]']);     // empty array -> no rows -> no match, no error
        $seed->execute([4, 'null']);   // null attachments -> no match, no error

        $stmt = $pdo->prepare("SELECT id FROM m WHERE {$predicate} = 1 ORDER BY id");
        $stmt->execute([$value]);
        /** @var list<string> $ids */
        $ids = $stmt->fetchAll(PDO::FETCH_COLUMN);

        self::assertSame($expectedIds, array_map('intval', $ids));
    }

    private function emit(AbstractPlatform $platform, string $haystack = 'n0_.attachments'): string
    {
        $function = new JsonArrayHasFunction('JSON_ARRAY_HAS');
        new ReflectionProperty(JsonArrayHasFunction::class, 'haystack')->setValue($function, new FakeRawNode($haystack));
        new ReflectionProperty(JsonArrayHasFunction::class, 'field')->setValue($function, new Literal(Literal::STRING, 'vfsNodeId'));
        new ReflectionProperty(JsonArrayHasFunction::class, 'value')->setValue($function, new InputParameter(':val'));

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

        // FakeRawNode dispatches to its raw string; Literal/InputParameter route
        // through the stubbed walker above.
        return $function->getSql($walker);
    }
}
