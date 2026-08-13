<?php

declare(strict_types=1);

namespace CoolMS\RqlDoctrine\DQL;

use Doctrine\ORM\Query\AST\Functions\FunctionNode;
use Doctrine\ORM\Query\AST\Literal;
use Doctrine\ORM\Query\AST\Node;
use Doctrine\ORM\Query\Parser;
use Doctrine\ORM\Query\QueryException;
use Doctrine\ORM\Query\SqlWalker;
use Doctrine\ORM\Query\TokenType;

/**
 * DQL: JSONB_CAST(expr, 'key', 'TYPE') -> SQL: CAST((expr->>'key') AS TYPE).
 *
 * PostgreSQL-only. Emits the exact expression used by the generated column
 * source created by PostgreSQLPlatformSchemaManager so the planner can inline
 * v_{key} and use the matching B-tree index.
 *
 * Bare CAST(...) is not a registered DQL function in Doctrine ORM, so folding
 * it into a single function call avoids a DQL parse error. The third argument
 * is a string literal validated against an allowlist in getSql().
 */
final class JsonbCastFunction extends FunctionNode
{
    private const array ALLOWED_TYPES = [
        'TEXT',
        'INTEGER',
        'BIGINT',
        'SMALLINT',
        'NUMERIC',
        'DOUBLE PRECISION',
        'REAL',
        'BOOLEAN',
        'TIMESTAMP',
        'DATE',
    ];

    private Node $source;
    private Node $key;
    private Literal $type;

    public function parse(Parser $parser): void
    {
        $parser->match(TokenType::T_IDENTIFIER);
        $parser->match(TokenType::T_OPEN_PARENTHESIS);
        $this->source = $parser->StringPrimary();
        $parser->match(TokenType::T_COMMA);
        $this->key = $parser->StringPrimary();
        $parser->match(TokenType::T_COMMA);
        $this->type = $parser->Literal();
        $parser->match(TokenType::T_CLOSE_PARENTHESIS);
    }

    public function getSql(SqlWalker $sqlWalker): string
    {
        $type = strtoupper((string) $this->type->value);
        if (!in_array($type, self::ALLOWED_TYPES, true)) {
            throw QueryException::semanticalError('JSONB_CAST type must be one of: ' . implode(', ', self::ALLOWED_TYPES) . '; got ' . $type);
        }

        return sprintf(
            'CAST((%s->>%s) AS %s)',
            $this->source->dispatch($sqlWalker),
            $this->key->dispatch($sqlWalker),
            $type,
        );
    }
}
