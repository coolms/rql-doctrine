<?php

declare(strict_types=1);

namespace CoolMS\Rql\Doctrine;

use Doctrine\DBAL\Connection;
use Throwable;

/**
 * Looks up the declared SQL type of a dynamic field, so a JSON extraction
 * expression can match the generated column's source and use its index.
 *
 * Reads the field-definition table directly rather than through an ORM entity:
 * the table name is the contract here, and this runs during query building,
 * where going back through the entity manager that is mid-build would be
 * circular.
 */
final class DynamicFieldSqlTypeResolver
{
    /** @var array<string, string> cache key "{entityAlias}|{fieldName}" => sqlType */
    private array $cache = [];

    /** @var array<string, bool> cache key "{entityAlias}|{fieldName}" => row exists */
    private array $presenceCache = [];

    public function __construct(
        private readonly Connection $connection,
    ) {
    }

    /**
     * Returns the SQL type string (TEXT, INTEGER, BOOLEAN, DOUBLE PRECISION, TIMESTAMP)
     * as PostgreSQL would name it. Platform visitors translate further if their syntax differs.
     * Returns 'TEXT' as safe fallback when the field is not found.
     */
    public function resolve(string $entityAlias, string $fieldName): string
    {
        $key = $entityAlias . '|' . $fieldName;
        if (isset($this->cache[$key])) {
            return $this->cache[$key];
        }

        try {
            $row = $this->connection->fetchAssociative(
                'SELECT type FROM coolms_field_definitions WHERE entity_alias = ? AND name = ?',
                [$entityAlias, $fieldName],
            );
        } catch (Throwable) {
            return $this->cache[$key] = 'TEXT';
        }

        if (false === $row) {
            return $this->cache[$key] = 'TEXT';
        }

        return $this->cache[$key] = match ((string) $row['type']) {
            'int' => 'INTEGER',
            'bool' => 'BOOLEAN',
            'float' => 'DOUBLE PRECISION',
            'datetime' => 'TIMESTAMP',
            default => 'TEXT',
        };
    }

    /**
     * Returns true when a FieldDefinition row exists for ($entityAlias, $fieldName).
     * Used by the visitor to decide whether to emit the v_* column reference (which
     * the planner can index) instead of the source CAST(extras->>'...') form.
     *
     * Falls back to false on connection or schema errors so the visitor downgrades
     * to its existing extras-extraction path rather than failing the request.
     */
    public function hasField(string $entityAlias, string $fieldName): bool
    {
        $key = $entityAlias . '|' . $fieldName;
        if (isset($this->presenceCache[$key])) {
            return $this->presenceCache[$key];
        }

        try {
            $exists = $this->connection->fetchOne(
                'SELECT 1 FROM coolms_field_definitions WHERE entity_alias = ? AND name = ? LIMIT 1',
                [$entityAlias, $fieldName],
            );
        } catch (Throwable) {
            return $this->presenceCache[$key] = false;
        }

        return $this->presenceCache[$key] = false !== $exists;
    }
}
