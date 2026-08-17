<?php

declare(strict_types=1);

namespace CoolMS\Rql\Doctrine\Platform;

use CoolMS\Rql\Doctrine\AbstractDoctrineJsonVisitor;

/**
 * PostgreSQL generated column source: CAST((extras->>'key') AS <sqltype>) STORED.
 *
 * When a FieldDefinition row exists for ($entityAlias, $key) the visitor emits
 * JSONB_VCOL, which expands to a direct v_{key} column reference so the planner
 * can use idx_{table}_{key} (and any LOWER() CI variant). Otherwise it falls back
 * to JSONB_CAST, which byte-matches the generated column source; either form is
 * correct, but only the v_* reference is index-aligned in PostgreSQL.
 */
final class PostgreSQLDoctrineJsonVisitor extends AbstractDoctrineJsonVisitor
{
    protected function jsonExtract(
        string $alias,
        string $key,
        string $sqlType,
        ?string $entityAlias,
    ): string {
        if (null !== $entityAlias && $this->typeResolver->hasField($entityAlias, $key)) {
            return "JSONB_VCOL($alias.extras, '$key')";
        }

        return "JSONB_CAST($alias.extras, '$key', '$sqlType')";
    }
}
