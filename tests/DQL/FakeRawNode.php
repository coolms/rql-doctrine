<?php

declare(strict_types=1);

namespace CoolMS\Rql\Doctrine\Tests\DQL;

use Doctrine\ORM\Query\AST\Node;
use Doctrine\ORM\Query\SqlWalker;

/**
 * Test-only AST stand-in that dispatches to a pre-baked SQL fragment.
 *
 * Real DQL parsing would route the source/key/path arguments through
 * SqlWalker visitors that resolve aliases and quote literals. Tests for
 * platform-aware FunctionNode emitters don't need that machinery -- we just
 * want to verify the assembled SQL pattern -- so this node returns its raw
 * string on dispatch.
 */
final class FakeRawNode extends Node
{
    public function __construct(private readonly string $sql)
    {
    }

    public function dispatch(SqlWalker $walker): string
    {
        return $this->sql;
    }
}
