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
 * DQL: JSONB_EXISTS(haystack, key) -- "does haystack have this top-level key?".
 *
 * Platform-aware existence test against a JSON document.
 *
 * Emission per platform (MySQL branch matches `AbstractMySQLPlatform`, which
 * covers MySQL, MariaDB and all version-specific subclasses):
 *   - PostgreSQL: `jsonb_exists((haystack)::jsonb, key)` (function form of the
 *     `?` operator -- the operator form would be misread as a positional
 *     placeholder by DBAL's SQL parser).
 *   - MySQL/MariaDB: `JSON_CONTAINS_PATH(haystack, 'one', CONCAT('$.', key))`
 *   - SQLite: `(json_extract(haystack, '$.' || key) IS NOT NULL)`
 *     Caveat: returns false for keys whose value is JSON null. The current
 *     use-site only needs presence of a marker like `extras ? 'error'`, so
 *     the JSON-null distinction does not matter today.
 *
 * Each form is wrapped in parens / is a single boolean call so
 * `NOT JSONB_EXISTS(...)` lowers cleanly.
 */
final class JsonbExistsFunction extends FunctionNode
{
    private Node $haystack;
    private Node $key;

    public function parse(Parser $parser): void
    {
        $parser->match(TokenType::T_IDENTIFIER);
        $parser->match(TokenType::T_OPEN_PARENTHESIS);
        $this->haystack = $parser->StringPrimary();
        $parser->match(TokenType::T_COMMA);
        $this->key = $parser->StringPrimary();
        $parser->match(TokenType::T_CLOSE_PARENTHESIS);
    }

    public function getSql(SqlWalker $sqlWalker): string
    {
        $platform = $sqlWalker->getConnection()->getDatabasePlatform();
        $haystackSql = $this->haystack->dispatch($sqlWalker);
        $keySql = $this->key->dispatch($sqlWalker);

        if ($platform instanceof PostgreSQLPlatform) {
            // Function form (jsonb_exists) instead of the `?` operator: DBAL's
            // SQL parser would otherwise see a bare `?` and treat it as a
            // positional placeholder, breaking parameter binding.
            return sprintf('jsonb_exists((%s)::jsonb, %s)', $haystackSql, $keySql);
        }

        if ($platform instanceof AbstractMySQLPlatform) {
            return sprintf("JSON_CONTAINS_PATH(%s, 'one', CONCAT('$.', %s))", $haystackSql, $keySql);
        }

        if ($platform instanceof SQLitePlatform) {
            return sprintf("(json_extract(%s, '$.' || %s) IS NOT NULL)", $haystackSql, $keySql);
        }

        throw QueryException::semanticalError(sprintf('JSONB_EXISTS is not implemented for platform "%s".', $platform::class));
    }
}
