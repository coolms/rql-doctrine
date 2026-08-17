<?php

declare(strict_types=1);

namespace CoolMS\Rql\Doctrine;

use CoolMS\Rql\Doctrine\Platform\MariaDBDoctrineJsonVisitor;
use CoolMS\Rql\Doctrine\Platform\MySQLDoctrineJsonVisitor;
use CoolMS\Rql\Doctrine\Platform\OracleDoctrineJsonVisitor;
use CoolMS\Rql\Doctrine\Platform\PostgreSQLDoctrineJsonVisitor;
use CoolMS\Rql\Doctrine\Platform\SQLiteDoctrineJsonVisitor;
use CoolMS\Rql\Doctrine\Platform\SQLServerDoctrineJsonVisitor;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Platforms\MariaDBPlatform;
use Doctrine\DBAL\Platforms\MySQLPlatform;
use Doctrine\DBAL\Platforms\OraclePlatform;
use Doctrine\DBAL\Platforms\PostgreSQLPlatform;
use Doctrine\DBAL\Platforms\SQLitePlatform;
use Doctrine\DBAL\Platforms\SQLServerPlatform;
use RuntimeException;

final class DoctrineJsonVisitorFactory
{
    /**
     * Match order mirrors PlatformSchemaManagerFactory:
     * MariaDB BEFORE MySQL (MariaDBPlatform extends MySQLPlatform in DBAL 4.x).
     */
    public static function create(
        Connection $connection,
        DynamicFieldSqlTypeResolver $typeResolver,
    ): AbstractDoctrineJsonVisitor {
        $platform = $connection->getDatabasePlatform();

        return match (true) {
            $platform instanceof MariaDBPlatform => new MariaDBDoctrineJsonVisitor($typeResolver),
            $platform instanceof MySQLPlatform => new MySQLDoctrineJsonVisitor($typeResolver),
            $platform instanceof PostgreSQLPlatform => new PostgreSQLDoctrineJsonVisitor($typeResolver),
            $platform instanceof SQLitePlatform => new SQLiteDoctrineJsonVisitor($typeResolver),
            $platform instanceof SQLServerPlatform => new SQLServerDoctrineJsonVisitor($typeResolver),
            $platform instanceof OraclePlatform => new OracleDoctrineJsonVisitor($typeResolver),
            default => throw new RuntimeException(sprintf('Unsupported database platform "%s" for JSON extras filtering. Supported platforms: MySQL, MariaDB, PostgreSQL, SQLite, SQL Server, Oracle.', $platform::class)),
        };
    }
}
