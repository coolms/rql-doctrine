<?php

declare(strict_types=1);

namespace CoolMS\RqlDoctrine\DQL;

use Doctrine\ORM\Query\AST\Functions\FunctionNode;
use Doctrine\ORM\Query\AST\Node;
use Doctrine\ORM\Query\Parser;
use Doctrine\ORM\Query\SqlWalker;
use Doctrine\ORM\Query\TokenType;

/**
 * DQL: JSON_VALUE_FN(expr, path) -> SQL: JSON_VALUE(expr, path).
 *
 * Oracle and SQL Server. Named JSON_VALUE_FN because JSON_VALUE may clash with
 * reserved words in the DQL lexer; the emitted SQL uses the real JSON_VALUE name.
 */
final class JsonValueFunction extends FunctionNode
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
            'JSON_VALUE(%s, %s)',
            $this->source->dispatch($sqlWalker),
            $this->path->dispatch($sqlWalker),
        );
    }
}
