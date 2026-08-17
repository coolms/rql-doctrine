<?php

declare(strict_types=1);

namespace CoolMS\Rql\Doctrine\DQL;

use Doctrine\ORM\Query\AST\Functions\FunctionNode;
use Doctrine\ORM\Query\AST\Node;
use Doctrine\ORM\Query\Parser;
use Doctrine\ORM\Query\QueryException;
use Doctrine\ORM\Query\SqlWalker;
use Doctrine\ORM\Query\TokenType;

/**
 * DQL: JSONB_VCOL(alias.extras, 'key') -> SQL: alias_sql.v_key.
 *
 * PostgreSQL-only counterpart to JsonbCastFunction. Emitted by the visitor when
 * a FieldDefinition row exists for (entityAlias, key) so the planner can use
 * the v_{key} STORED generated column directly (and any index on it) instead
 * of seq-scanning the source CAST((extras->>'key') AS ...) expression.
 *
 * The first argument is parsed as a Doctrine path expression (e.g., `pv.extras`)
 * so SqlWalker translates the DQL alias to the runtime SQL alias. The trailing
 * `.extras` segment is discarded; `.v_{key}` is appended to the SQL alias.
 *
 * The key argument is restricted to [a-z0-9_]+ matching Definition::$name regex,
 * which keeps the emitted SQL identifier safe without quoting.
 */
final class JsonbVColFunction extends FunctionNode
{
    private Node $source;
    private Node $key;

    public function parse(Parser $parser): void
    {
        $parser->match(TokenType::T_IDENTIFIER);
        $parser->match(TokenType::T_OPEN_PARENTHESIS);
        $this->source = $parser->StringPrimary();
        $parser->match(TokenType::T_COMMA);
        $this->key = $parser->StringPrimary();
        $parser->match(TokenType::T_CLOSE_PARENTHESIS);
    }

    public function getSql(SqlWalker $sqlWalker): string
    {
        $key = $this->extractStringLiteral($this->key, $sqlWalker);
        if (1 !== preg_match('/^[a-z0-9_]+$/i', $key)) {
            throw QueryException::semanticalError("JSONB_VCOL key must match [a-z0-9_]+; got '$key'.");
        }

        $source = $this->source->dispatch($sqlWalker);
        $dot = strrpos($source, '.');
        if (false === $dot) {
            throw QueryException::semanticalError("JSONB_VCOL source expression must be a path like alias.extras; got '$source'.");
        }
        $tableAlias = substr($source, 0, $dot);

        return "$tableAlias.v_$key";
    }

    /**
     * Reads a quoted string literal back from the dispatched SQL fragment.
     * The Parser dispatches StringPrimary into an SQL-quoted form ('value');
     * strip the surrounding quotes (and any doubled-up internal quotes) so we
     * can validate the raw key against the identifier regex.
     */
    private function extractStringLiteral(Node $node, SqlWalker $sqlWalker): string
    {
        $sql = $node->dispatch($sqlWalker);
        if (str_starts_with($sql, "'") && str_ends_with($sql, "'")) {
            $sql = substr($sql, 1, -1);

            return str_replace("''", "'", $sql);
        }

        return $sql;
    }
}
