<?php

declare(strict_types=1);

namespace CoolMS\Rql\Doctrine\DQL;

use Doctrine\ORM\Query\AST\Functions\FunctionNode;
use Doctrine\ORM\Query\AST\Node;
use Doctrine\ORM\Query\Parser;
use Doctrine\ORM\Query\SqlWalker;
use Doctrine\ORM\Query\TokenType;

/**
 * DQL: SQLITE_JSON_EXTRACT(expr, path) -> SQL: json_extract(expr, path).
 *
 * SQLite. Doctrine ORM does not register lowercase json_extract for SQLite,
 * so we wrap it to cover the dev/test path (no generated column, O(n) scan).
 */
final class SqliteJsonExtractFunction extends FunctionNode
{
    private Node $source;
    private Node $path;

    public function parse(Parser $parser): void
    {
        $parser->match(TokenType::T_IDENTIFIER);
        $parser->match(TokenType::T_OPEN_PARENTHESIS);
        $this->source = $parser->StringPrimary();
        $parser->match(TokenType::T_COMMA);
        $this->path = $parser->StringPrimary();
        $parser->match(TokenType::T_CLOSE_PARENTHESIS);
    }

    public function getSql(SqlWalker $sqlWalker): string
    {
        return sprintf(
            'json_extract(%s, %s)',
            $this->source->dispatch($sqlWalker),
            $this->path->dispatch($sqlWalker),
        );
    }
}
