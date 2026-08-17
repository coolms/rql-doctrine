<?php

declare(strict_types=1);

use CoolMS\Rql\Doctrine\AbstractDoctrineJsonVisitor;
use CoolMS\Rql\Doctrine\DoctrineJsonVisitorFactory;
use CoolMS\Rql\Doctrine\DoctrineRqlVisitor;
use CoolMS\Rql\Doctrine\DynamicFieldSqlTypeResolver;
use Doctrine\DBAL\Connection;

use function Symfony\Component\DependencyInjection\Loader\Configurator\service;

return static function (Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator $container): void {
    $services = $container->services();

    $services->set(DynamicFieldSqlTypeResolver::class)
        ->args([service(Connection::class)]);

    // Which visitor you get depends on the connected platform: each emits SQL
    // byte-compatible with the generated column its paired schema manager
    // created, so a query can use the indexed column instead of re-extracting
    // the JSON. Resolved at container build time, not per request.
    $services->set(AbstractDoctrineJsonVisitor::class)
        ->factory([DoctrineJsonVisitorFactory::class, 'create'])
        ->args([
            service(Connection::class),
            service(DynamicFieldSqlTypeResolver::class),
        ]);

    $services->set(DoctrineRqlVisitor::class)
        ->args([
            service(AbstractDoctrineJsonVisitor::class),
            // $predicateContributors is filled by PredicateContributorPass;
            // an empty default keeps the service valid when nothing contributes.
            [],
        ])
        ->public();

    $services->alias('coolms.rql_doctrine.visitor', DoctrineRqlVisitor::class);
};
