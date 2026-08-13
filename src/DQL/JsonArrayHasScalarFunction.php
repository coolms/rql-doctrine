<?php

declare(strict_types=1);

namespace CoolMS\RqlDoctrine\DQL;

use Doctrine\DBAL\Platforms\AbstractMySQLPlatform;
use Doctrine\DBAL\Platforms\PostgreSQLPlatform;
use Doctrine\DBAL\Platforms\SQLitePlatform;
use Doctrine\ORM\Query\AST\Functions\FunctionNode;
use Doctrine\ORM\Query\AST\Node;
use Doctrine\ORM\Query\Parser;
use Doctrine\ORM\Query\QueryException;
use Doctrine\ORM\Query\SqlWalker;
use Doctrine\ORM\Query\TokenType;

use function sprintf;

/**
 * DQL: JSON_ARRAY_HAS_SCALAR(haystack, value) -- "does the JSON array
 * `haystack` contain the scalar element `value`?".
 *
 * The plain-array sibling of {@see JsonArrayHasFunction}, which needs a
 * `field` because it searches arrays of OBJECTS. Neither it nor
 * {@see JsonbContainsFunction} can answer membership in an array of bare
 * strings, e.g. `["premium","eu"]`.
 *
 * Why it exists: without a portable predicate for that shape, the honest
 * fallback is to filter in PHP, which means reading every row of the table
 * before discarding most of them. On the table where this came up that was one
 * row per visitor. This removes the premise rather than working around it.
 *
 * Emission per platform (the MySQL branch matches `AbstractMySQLPlatform`, so
 * it covers MySQL, MariaDB and every version-specific subclass):
 *   - PostgreSQL: `((haystack)::jsonb @> jsonb_build_array((value)::text))`
 *   - MySQL/MariaDB: `JSON_CONTAINS(haystack, JSON_QUOTE(value))`
 *   - SQLite: `EXISTS (SELECT 1 FROM json_each(haystack) e WHERE e.value = value)`
 *
 * `json_each` over a NULL or non-array haystack simply yields no rows -- false,
 * never an error -- matching the other functions' defensive posture. Each form
 * is a single parenthesised / call-shaped boolean, so a caller's `= TRUE` /
 * `NOT ...` lowers cleanly.
 */
final class JsonArrayHasScalarFunction extends FunctionNode
{
    private Node $haystack;
    private Node $value;

    public function parse(Parser $parser): void
    {
        $parser->match(TokenType::T_IDENTIFIER);
        $parser->match(TokenType::T_OPEN_PARENTHESIS);
        $this->haystack = $parser->StringPrimary();
        $parser->match(TokenType::T_COMMA);
        $this->value = $parser->StringPrimary();
        $parser->match(TokenType::T_CLOSE_PARENTHESIS);
    }

    public function getSql(SqlWalker $sqlWalker): string
    {
        $platform = $sqlWalker->getConnection()->getDatabasePlatform();
        $haystackSql = $this->haystack->dispatch($sqlWalker);
        $valueSql = $this->value->dispatch($sqlWalker);

        if ($platform instanceof PostgreSQLPlatform) {
            // `(value)::text` for the same reason JSON_ARRAY_HAS casts: without
            // it, the bound parameter reaches `jsonb_build_array`'s "any" arg
            // untyped and Postgres raises "could not determine data type of
            // parameter" (SQLSTATE 42P18).
            return sprintf('((%s)::jsonb @> jsonb_build_array((%s)::text))', $haystackSql, $valueSql);
        }

        if ($platform instanceof AbstractMySQLPlatform) {
            // JSON_QUOTE turns the bare parameter into a JSON string scalar so
            // JSON_CONTAINS compares like-for-like against the array elements.
            return sprintf('JSON_CONTAINS(%s, JSON_QUOTE(%s))', $haystackSql, $valueSql);
        }

        if ($platform instanceof SQLitePlatform) {
            return sprintf(
                'EXISTS (SELECT 1 FROM json_each(%s) AS __e WHERE __e.value = %s)',
                $haystackSql,
                $valueSql,
            );
        }

        throw QueryException::semanticalError(sprintf('JSON_ARRAY_HAS_SCALAR is not implemented for platform "%s".', $platform::class));
    }
}
