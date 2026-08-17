<?php

declare(strict_types=1);

namespace CoolMS\Rql\Doctrine\DQL;

use Doctrine\DBAL\Platforms\AbstractMySQLPlatform;
use Doctrine\DBAL\Platforms\PostgreSQLPlatform;
use Doctrine\DBAL\Platforms\SQLitePlatform;
use Doctrine\ORM\Query\AST\Functions\FunctionNode;
use Doctrine\ORM\Query\AST\Node;
use Doctrine\ORM\Query\Parser;
use Doctrine\ORM\Query\SqlWalker;
use Doctrine\ORM\Query\TokenType;
use RuntimeException;

/**
 * DQL: JSONB_GET_TEXT(haystack, key) -- "read the top-level text value at key".
 *
 * Platform-aware text extraction from a JSON document. Returns SQL whose value
 * is the unquoted text at `haystack -> key`, or NULL when the key is absent.
 *
 * Emission per platform (MySQL branch matches `AbstractMySQLPlatform`, which
 * covers MySQL, MariaDB and all version-specific subclasses):
 *   - PostgreSQL: `(haystack->>key)` -- the raw JSON text-extraction operator,
 *     so the planner can still inline `v_{key}` generated columns when present.
 *   - MySQL/MariaDB: `JSON_UNQUOTE(JSON_EXTRACT(haystack, CONCAT('$.', key)))`
 *     -- JSON_UNQUOTE strips the double-quotes JSON_EXTRACT wraps string scalars
 *     in. Missing path returns NULL, matching Postgres semantics. The CONCAT
 *     form works for both string-literal and bound-parameter keys.
 *   - SQLite: `json_extract(haystack, '$.' || key)` -- SQLite's json_extract
 *     returns unquoted strings natively and yields NULL for missing paths.
 *     The `||` concatenation works for both literal and parametric keys.
 *
 * This is THE cross-platform JSON-text-extraction primitive; use it for all
 * portable query code (it supersedes the retired Postgres-only `->>` helper).
 */
final class JsonbGetTextFunction extends FunctionNode
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
            return sprintf('(%s->>%s)', $haystackSql, $keySql);
        }

        if ($platform instanceof AbstractMySQLPlatform) {
            return sprintf("JSON_UNQUOTE(JSON_EXTRACT(%s, CONCAT('$.', %s)))", $haystackSql, $keySql);
        }

        if ($platform instanceof SQLitePlatform) {
            return sprintf("json_extract(%s, '$.' || %s)", $haystackSql, $keySql);
        }

        throw new RuntimeException(sprintf('JSONB_GET_TEXT is not implemented for platform "%s".', $platform::class));
    }
}
