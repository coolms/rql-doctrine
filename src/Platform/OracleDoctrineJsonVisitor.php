<?php

declare(strict_types=1);

namespace CoolMS\RqlDoctrine\Platform;

use CoolMS\RqlDoctrine\AbstractDoctrineJsonVisitor;

/**
 * Oracle generated column source: JSON_VALUE(extras, '$.key') VIRTUAL.
 * JSON_VALUE_FN DQL function emits JSON_VALUE(alias.extras, '$.key') at the SQL level.
 *
 * When a FieldDefinition row exists for ($entityAlias, $key) the visitor emits
 * JSONB_VCOL, which expands to a direct v_{key} column reference so the planner
 * can use the matching index. Otherwise it falls back to the JSON_VALUE form
 * that byte-matches the generated column source.
 */
final class OracleDoctrineJsonVisitor extends AbstractDoctrineJsonVisitor
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

        return "JSON_VALUE_FN($alias.extras, '$.$key')";
    }
}
