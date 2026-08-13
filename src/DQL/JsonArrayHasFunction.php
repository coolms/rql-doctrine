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

/**
 * DQL: JSON_ARRAY_HAS(haystack, field, value) -- "does the JSON *array*
 * `haystack` contain an element whose top-level `field` equals `value`?".
 *
 * This is array-element containment, semantically DIFFERENT from the flat-object
 * containment of {@see JsonbContainsFunction} -- so use THIS for "an array holds
 * an element matching X" (e.g. Chat's `attachments` lookup by `vfsNodeId`), never
 * `JSONB_CONTAINS` (whose SQLite branch does not support array needles). `field`
 * is normally a compile-time string literal (e.g. 'vfsNodeId'); `value` a bound
 * parameter.
 *
 * Emission per platform (MySQL branch matches `AbstractMySQLPlatform`, which
 * covers MySQL, MariaDB and all version-specific subclasses):
 *   - PostgreSQL: `((haystack)::jsonb @> jsonb_build_array(jsonb_build_object(field, value)))`
 *   - MySQL/MariaDB: `JSON_CONTAINS(haystack, JSON_OBJECT(field, value))`
 *   - SQLite: `EXISTS (SELECT 1 FROM json_each(haystack) e WHERE json_extract(e.value, '$.' || field) = value)`
 *     (`json_each` over a non-array or NULL haystack simply yields no rows -> false, never errors.)
 *
 * Each form is a single parenthesised / call-shaped boolean, so a caller's
 * `= TRUE` / `= FALSE` / `NOT JSON_ARRAY_HAS(...)` lowers cleanly.
 */
final class JsonArrayHasFunction extends FunctionNode
{
    private Node $haystack;
    private Node $field;
    private Node $value;

    public function parse(Parser $parser): void
    {
        $parser->match(TokenType::T_IDENTIFIER);
        $parser->match(TokenType::T_OPEN_PARENTHESIS);
        $this->haystack = $parser->StringPrimary();
        $parser->match(TokenType::T_COMMA);
        $this->field = $parser->StringPrimary();
        $parser->match(TokenType::T_COMMA);
        $this->value = $parser->StringPrimary();
        $parser->match(TokenType::T_CLOSE_PARENTHESIS);
    }

    public function getSql(SqlWalker $sqlWalker): string
    {
        $platform = $sqlWalker->getConnection()->getDatabasePlatform();
        $haystackSql = $this->haystack->dispatch($sqlWalker);
        $fieldSql = $this->field->dispatch($sqlWalker);
        $valueSql = $this->value->dispatch($sqlWalker);

        if ($platform instanceof PostgreSQLPlatform) {
            // `(value)::text` so Postgres can infer the bound parameter's type;
            // without the cast `jsonb_build_object`'s `"any"` value arg yields
            // "could not determine data type of parameter" (SQLSTATE 42P18).
            return sprintf('((%s)::jsonb @> jsonb_build_array(jsonb_build_object(%s, (%s)::text)))', $haystackSql, $fieldSql, $valueSql);
        }

        if ($platform instanceof AbstractMySQLPlatform) {
            return sprintf('JSON_CONTAINS(%s, JSON_OBJECT(%s, %s))', $haystackSql, $fieldSql, $valueSql);
        }

        if ($platform instanceof SQLitePlatform) {
            return sprintf(
                "EXISTS (SELECT 1 FROM json_each(%s) AS __e WHERE json_extract(__e.value, '$.' || %s) = %s)",
                $haystackSql,
                $fieldSql,
                $valueSql,
            );
        }

        throw QueryException::semanticalError(sprintf('JSON_ARRAY_HAS is not implemented for platform "%s".', $platform::class));
    }
}
