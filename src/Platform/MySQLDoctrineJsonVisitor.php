<?php

declare(strict_types=1);

namespace CoolMS\RqlDoctrine\Platform;

use CoolMS\RqlDoctrine\AbstractDoctrineJsonVisitor;

/**
 * MySQL generated column source: JSON_UNQUOTE(JSON_EXTRACT(extras, '$.key')) VIRTUAL.
 *
 * When a FieldDefinition row exists for ($entityAlias, $key) the visitor emits
 * JSONB_VCOL, which expands to a direct v_{key} column reference so the planner
 * can use the matching index instead of evaluating the JSON expression per row.
 * Otherwise it falls back to the JSON_UNQUOTE(JSON_EXTRACT(...)) form that
 * byte-matches the generated column source produced by MySQLPlatformSchemaManager.
 */
final class MySQLDoctrineJsonVisitor extends AbstractDoctrineJsonVisitor
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

        return "JSON_UNQUOTE(JSON_EXTRACT($alias.extras, '$.$key'))";
    }
}
