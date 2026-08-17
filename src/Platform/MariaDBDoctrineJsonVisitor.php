<?php

declare(strict_types=1);

namespace CoolMS\Rql\Doctrine\Platform;

use CoolMS\Rql\Doctrine\AbstractDoctrineJsonVisitor;

/**
 * MariaDB generated column source: JSON_UNQUOTE(JSON_EXTRACT(extras, '$.key')) VIRTUAL.
 * Matches MySQL syntax; kept as a sibling class so the factory order mirrors
 * PlatformSchemaManagerFactory (MariaDBPlatform extends MySQLPlatform in DBAL 4.x).
 *
 * When a FieldDefinition row exists for ($entityAlias, $key) the visitor emits
 * JSONB_VCOL, which expands to a direct v_{key} column reference so the planner
 * can use the matching index. Otherwise it falls back to the JSON_UNQUOTE(JSON_EXTRACT(...))
 * form that byte-matches the generated column source.
 */
final class MariaDBDoctrineJsonVisitor extends AbstractDoctrineJsonVisitor
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
