<?php

declare(strict_types=1);

namespace CoolMS\Rql\Doctrine\Tests\DQL;

use CoolMS\Rql\Doctrine\DQL\JsonArrayHasScalarFunction;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Platforms\AbstractPlatform;
use Doctrine\DBAL\Platforms\MariaDBPlatform;
use Doctrine\DBAL\Platforms\MySQLPlatform;
use Doctrine\DBAL\Platforms\OraclePlatform;
use Doctrine\DBAL\Platforms\PostgreSQLPlatform;
use Doctrine\DBAL\Platforms\SQLitePlatform;
use Doctrine\ORM\Query\AST\InputParameter;
use Doctrine\ORM\Query\QueryException;
use Doctrine\ORM\Query\SqlWalker;
use PDO;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use ReflectionProperty;

use function array_map;

/**
 * `JSON_ARRAY_HAS_SCALAR(haystack, value)` -- membership of a scalar in a JSON
 * array of bare strings, e.g. `["premium","eu"]`.
 *
 * The sibling {@see \CoolMS\Rql\Doctrine\DQL\JsonArrayHasFunction} cannot answer
 * it: it takes a `field`, because it searches arrays of OBJECTS. Without a
 * portable predicate for this shape the fallback is filtering in PHP over a
 * full table read; these cases are what let that move into SQL.
 */
#[CoversClass(JsonArrayHasScalarFunction::class)]
final class JsonArrayHasScalarFunctionTest extends TestCase
{
    /**
     * @return iterable<string, array{string, list<int>}>
     */
    public static function scalarElementCases(): iterable
    {
        yield 'matches first element' => ['vip', [1]];
        yield 'matches a non-first element' => ['churn-risk', [1]];
        yield 'matches single-element array' => ['engaged', [2]];
        yield 'no element matches' => ['nobody', []];
        // The regression the exact predicate exists to prevent: the column used
        // to be a `cn` substring filter over the FE's joined label string, where
        // "vip" also matched the row holding only "vip-gold".
        yield 'prefix of an element is NOT a match' => ['vip-gold', [5]];
    }

    #[Test]
    public function postgresEmitsJsonbContainmentOfBuiltArray(): void
    {
        self::assertSame(
            '((s0_.segments)::jsonb @> jsonb_build_array((?)::text))',
            $this->emit(new PostgreSQLPlatform()),
        );
    }

    #[Test]
    public function mysqlEmitsJsonContainsOfQuotedScalar(): void
    {
        self::assertSame(
            'JSON_CONTAINS(s0_.segments, JSON_QUOTE(?))',
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
            'EXISTS (SELECT 1 FROM json_each(s0_.segments) AS __e WHERE __e.value = ?)',
            $this->emit(new SQLitePlatform()),
        );
    }

    /**
     * An unsupported platform fails LOUDLY at query-build time rather than
     * emitting SQL that silently matches nothing.
     */
    #[Test]
    public function unsupportedPlatformThrows(): void
    {
        $this->expectException(QueryException::class);
        $this->expectExceptionMessageMatches('/JSON_ARRAY_HAS_SCALAR is not implemented/');

        $this->emit(new OraclePlatform());
    }

    /**
     * Execute the function's REAL emitted SQL against a seeded in-memory SQLite
     * DB, so the `json_each` lowering is proven to behave like Postgres `@>`
     * array containment -- not merely asserted as a string.
     *
     * @param list<int> $expectedIds
     */
    #[Test]
    #[DataProvider('scalarElementCases')]
    public function sqliteRuntimeContainmentMatchesElements(string $value, array $expectedIds): void
    {
        $predicate = $this->emit(new SQLitePlatform(), 's.segments');

        $pdo = new PDO('sqlite::memory:');
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $pdo->exec('CREATE TABLE s (id INTEGER PRIMARY KEY, segments TEXT)');
        $seed = $pdo->prepare('INSERT INTO s (id, segments) VALUES (?, ?)');
        $seed->execute([1, '["vip", "churn-risk"]']);
        $seed->execute([2, '["engaged"]']);
        $seed->execute([3, '[]']);     // empty array -> no rows -> no match, no error
        $seed->execute([4, 'null']);   // null segments -> no match, no error
        $seed->execute([5, '["vip-gold"]']);

        $stmt = $pdo->prepare("SELECT id FROM s WHERE {$predicate} = 1 ORDER BY id");
        $stmt->execute([$value]);
        /** @var list<string> $ids */
        $ids = $stmt->fetchAll(PDO::FETCH_COLUMN);

        self::assertSame($expectedIds, array_map('intval', $ids));
    }

    private function emit(AbstractPlatform $platform, string $haystack = 's0_.segments'): string
    {
        $function = new JsonArrayHasScalarFunction('JSON_ARRAY_HAS_SCALAR');
        new ReflectionProperty(JsonArrayHasScalarFunction::class, 'haystack')->setValue($function, new FakeRawNode($haystack));
        new ReflectionProperty(JsonArrayHasScalarFunction::class, 'value')->setValue($function, new InputParameter(':val'));

        $connection = $this->createStub(Connection::class);
        $connection->method('getDatabasePlatform')->willReturn($platform);

        $walker = $this->createStub(SqlWalker::class);
        $walker->method('getConnection')->willReturn($connection);
        $walker->method('walkInputParameter')->willReturn('?');

        return $function->getSql($walker);
    }
}
