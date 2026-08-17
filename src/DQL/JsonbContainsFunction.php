<?php

declare(strict_types=1);

namespace CoolMS\Rql\Doctrine\DQL;

use Doctrine\DBAL\Platforms\AbstractMySQLPlatform;
use Doctrine\DBAL\Platforms\PostgreSQLPlatform;
use Doctrine\DBAL\Platforms\SQLitePlatform;
use Doctrine\ORM\Query\AST\Functions\FunctionNode;
use Doctrine\ORM\Query\AST\Literal;
use Doctrine\ORM\Query\AST\Node;
use Doctrine\ORM\Query\Parser;
use Doctrine\ORM\Query\QueryException;
use Doctrine\ORM\Query\SqlWalker;
use Doctrine\ORM\Query\TokenType;
use RuntimeException;

/**
 * DQL: JSONB_CONTAINS(haystack, needle) -- "does haystack contain needle?".
 *
 * Platform-aware containment test against a JSON document. Returns SQL that
 * evaluates to a boolean (or 0/1) depending on the platform.
 *
 * Emission per platform (MySQL branch matches `AbstractMySQLPlatform`, which
 * covers MySQL, MariaDB and all version-specific subclasses):
 *   - PostgreSQL: `((haystack)::jsonb @> (needle)::jsonb)`
 *   - MySQL/MariaDB: `JSON_CONTAINS(haystack, needle)` (returns 0/1)
 *   - SQLite: no native containment operator, so emit an equivalent for a flat
 *     (top-level) JSON *object* needle -- either a string-literal marker lifted
 *     to a conjunction of `json_extract(...) = <literal>` predicates, or a bound
 *     parameter lowered to a runtime `json_each` containment check (so dynamic
 *     `:marker` queries stay portable). See {@see self::buildSqliteContains}.
 *
 * Negation: the Postgres/MySQL forms are wrapped in parens, so `NOT JSONB_CONTAINS(...)`
 * lowers cleanly to `NOT (...)`. The SQLite forms are likewise parenthesised.
 *
 * Limitation: the SQLite emitter supports flat (top-level) JSON *object* needles
 * only. A non-object needle (e.g. a JSON *array*, as used for "array contains an
 * element matching X") yields a defined `false` on SQLite -- callers needing
 * array-element containment must use a dedicated array primitive, not this.
 */
final class JsonbContainsFunction extends FunctionNode
{
    private Node $haystack;
    private Node $needle;

    public function parse(Parser $parser): void
    {
        $parser->match(TokenType::T_IDENTIFIER);
        $parser->match(TokenType::T_OPEN_PARENTHESIS);
        $this->haystack = $parser->StringPrimary();
        $parser->match(TokenType::T_COMMA);
        $this->needle = $parser->StringPrimary();
        $parser->match(TokenType::T_CLOSE_PARENTHESIS);
    }

    public function getSql(SqlWalker $sqlWalker): string
    {
        $platform = $sqlWalker->getConnection()->getDatabasePlatform();
        $haystackSql = $this->haystack->dispatch($sqlWalker);
        $needleSql = $this->needle->dispatch($sqlWalker);

        if ($platform instanceof PostgreSQLPlatform) {
            return sprintf('((%s)::jsonb @> (%s)::jsonb)', $haystackSql, $needleSql);
        }

        if ($platform instanceof AbstractMySQLPlatform) {
            return sprintf('JSON_CONTAINS(%s, %s)', $haystackSql, $needleSql);
        }

        if ($platform instanceof SQLitePlatform) {
            return $this->buildSqliteContains($haystackSql, $needleSql);
        }

        throw QueryException::semanticalError(sprintf('JSONB_CONTAINS is not implemented for platform "%s".', $platform::class));
    }

    /**
     * SQLite has no native JSON containment operator, so emit an equivalent.
     *
     * Two shapes:
     *  - **String literal needle** ({@see Literal}): the marker is known at
     *    compile time, so lift each (key, value) pair to a conjunction of
     *    `json_extract(...) = <literal>` checks (fast, no subquery).
     *  - **Bound parameter / any non-literal needle** (e.g. `:marker`): the
     *    value is unknown at compile time, so lower to a RUNTIME containment
     *    check via `json_each` over the needle. For a flat JSON *object* needle
     *    `{k:v, ...}` this is true iff the haystack has every key with an equal
     *    scalar value -- matching Postgres `@>` / MySQL `JSON_CONTAINS` for flat
     *    objects. A non-object needle (e.g. a JSON array) yields a defined
     *    `false` here: SQLite array containment is intentionally NOT supported --
     *    callers needing "array has an element matching X" must use a dedicated
     *    array primitive, not JSONB_CONTAINS.
     */
    private function buildSqliteContains(string $haystackSql, string $needleSql): string
    {
        if (!$this->needle instanceof Literal || Literal::STRING !== $this->needle->type) {
            // Runtime flat-object containment: NO needle key is missing-or-unequal
            // in the haystack. `IS NOT` (not `!=`) so a missing key (NULL) counts
            // as a mismatch. Parenthesised so `= TRUE` / `= FALSE` / `NOT (...)`
            // from the caller lower cleanly.
            return sprintf(
                '((SELECT COUNT(*) FROM json_each(%s) AS __je '
                . 'WHERE json_extract(%s, \'$.\' || __je.key) IS NOT __je.value) = 0)',
                $needleSql,
                $haystackSql,
            );
        }

        /** @var string $raw */
        $raw = $this->needle->value;
        $decoded = json_decode($raw, true);
        if (!is_array($decoded) || [] === $decoded) {
            throw new RuntimeException(sprintf('JSONB_CONTAINS on SQLite needs a non-empty JSON object literal as needle; got %s.', $raw));
        }

        $parts = [];
        foreach ($decoded as $key => $value) {
            if (!is_string($key) || !is_scalar($value)) {
                throw new RuntimeException('JSONB_CONTAINS on SQLite supports only flat object literals with scalar values.');
            }
            $path = "'$." . str_replace("'", "''", $key) . "'";
            $literal = match (true) {
                is_bool($value) => $value ? '1' : '0',
                is_int($value), is_float($value) => (string) $value,
                default => "'" . str_replace("'", "''", (string) $value) . "'",
            };
            $parts[] = sprintf('json_extract(%s, %s) = %s', $haystackSql, $path, $literal);
        }

        return '(' . implode(' AND ', $parts) . ')';
    }
}
