<?php

declare(strict_types=1);

namespace CoolMS\RqlDoctrine\Platform;

use CoolMS\RqlDoctrine\AbstractDoctrineJsonVisitor;

/**
 * SQLite has no generated column for dynamic fields (SQLitePlatformSchemaManager
 * returns empty SQL). Filter runs at O(n) full scan -- acceptable for dev/test
 * environments; production should use PostgreSQL, MySQL, or MariaDB.
 *
 * No JSONB_VCOL branch: SQLite does not support generated columns (per
 * SQLitePlatformSchemaManager), so v_* columns never exist on this platform
 * and the visitor always uses the JSON-extract form.
 */
final class SQLiteDoctrineJsonVisitor extends AbstractDoctrineJsonVisitor
{
    protected function jsonExtract(
        string $alias,
        string $key,
        string $sqlType,
        ?string $entityAlias,
    ): string {
        return "SQLITE_JSON_EXTRACT($alias.extras, '$.$key')";
    }
}
