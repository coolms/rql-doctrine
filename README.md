# coolms/rql-doctrine

[![CI](https://github.com/coolms/rql-doctrine/actions/workflows/ci.yml/badge.svg)](https://github.com/coolms/rql-doctrine/actions/workflows/ci.yml)
[![Latest Version](https://img.shields.io/packagist/v/coolms/rql-doctrine)](https://packagist.org/packages/coolms/rql-doctrine)
[![PHP](https://img.shields.io/badge/php-%E2%89%A5%208.5-777bb4)](https://www.php.net/releases/8.5/en.php)
[![License](https://img.shields.io/badge/license-MIT-green)](LICENSE)

**Doctrine ORM translator for [`coolms/rql`](https://github.com/coolms/rql).**
Walks the RQL AST onto a `QueryBuilder` and hands back a paginated result,
including JSON field filtering that works across six database platforms.

`coolms/rql` is the pure layer: two grammars, one immutable AST, a field
whitelist. It deliberately ships no translator, so that it depends on nothing.
This is that translator.

```php
$query  = (new RqlParser())->parse('filter=price gt 100&sort=-createdAt&limit=20');
$result = $visitor->apply($query, $repository->createQueryBuilder('n'), $context);

$result->items;       // the page of hydrated entities
$result->totalItems;  // total matching, ignoring pagination
```

## Installation

```bash
composer require coolms/rql-doctrine
```

Requires PHP `^8.5`, `doctrine/orm` `^3.0`, `doctrine/dbal` `^4.0` and Symfony
`^8.0`. The Symfony floor is 8 rather than 7 because this package requires PHP
8.5, and Symfony 7 emits PHP 8.4 deprecations under it.

### Symfony

Register the bundle:

```php
// config/bundles.php
CoolMS\Rql\Doctrine\RqlDoctrineBundle::class => ['all' => true],
```

That is enough for a single-manager application. If your entity manager is not
called `default`, name it, or the JSON DQL functions land on a manager that does
not exist and go missing at query time rather than at build time:

```yaml
# config/packages/rql_doctrine.yaml
rql_doctrine:
    entity_managers: ['central']
```

Without Symfony, construct the visitor yourself and register the DQL functions
listed below on your ORM configuration.

---

## What you get

| Service | Purpose |
|---|---|
| `DoctrineRqlVisitor` | walks the AST onto a `QueryBuilder`; `apply()`, `applyFilters()`, `applySort()` |
| `AbstractDoctrineJsonVisitor` | JSON path filtering, resolved to the platform you are connected to |
| `DynamicFieldSqlTypeResolver` | looks up the declared SQL type of a JSON field so casts line up with the index |

## Operators

Every operator in `coolms/rql`'s `FilterOp` is translated. `cn`/`bw`/`ew` compare
`LOWER(col) LIKE :v` against an already lower-cased value, so they are
case-insensitive as the AST expects.

Relation traversal one level deep (`identifiers.value`) becomes a correlated
`EXISTS` subquery rather than a join, so a filter cannot multiply rows.

## JSON fields

A field like `extras.price` is filtered through the platform visitor. Each
platform emits SQL byte-compatible with the generated column its schema manager
creates, so a query uses the indexed column instead of re-extracting the JSON:

| Platform | Visitor |
|---|---|
| PostgreSQL | `PostgreSQLDoctrineJsonVisitor` |
| MySQL | `MySQLDoctrineJsonVisitor` |
| MariaDB | `MariaDBDoctrineJsonVisitor` |
| SQLite | `SQLiteDoctrineJsonVisitor` |
| SQL Server | `SQLServerDoctrineJsonVisitor` |
| Oracle | `OracleDoctrineJsonVisitor` |

Selection happens once, at container build time, from the connected platform.
MariaDB is matched before MySQL because `MariaDBPlatform` extends
`MySQLPlatform` in DBAL 4 and the two emit different JSON SQL.

These DQL functions are registered for you by the bundle:

```
JSONB_CAST   JSONB_VCOL   JSONB_CONTAINS   JSONB_EXISTS   JSONB_GET_TEXT
JSON_VALUE_FN   JSON_ARRAY_HAS   JSON_ARRAY_HAS_SCALAR   SQLITE_JSON_EXTRACT
```

## Contributing predicates

A module can add filtering for fields the entity does not own, without touching
any repository. Implement `FilterPredicateContributorInterface`; the bundle tags
it and the visitor picks it up:

```php
final class TagPredicateContributor implements FilterPredicateContributorInterface
{
    public function supports(string $rootClass, string $field): bool
    {
        return 'tags' === $field;
    }

    public function apply(/* ... */): void
    {
        // add your EXISTS subquery to the QueryBuilder
    }
}
```

The visitor is the single funnel every repository's RQL passes through, so a
contributor needs no constructor change anywhere.

Outside Symfony, tag manually with
`coolms.rql_doctrine.filter_predicate_contributor`.

## Security

Field access is enforced by `coolms/rql`'s `RqlContext`, not here. A field
missing from the whitelist throws before this package sees it. Do not pass a
context built from client input.

## Writing your own translator

If you need a different ORM, the AST is the contract and one trap is worth
knowing: the boolean-group branch must be exhaustive over `FilterOp`. A group
builder that returns nothing for some operators does not error, it silently
drops that alternative out of the OR and returns the wrong rows. Assert on the
generated query text rather than the row count.

## License

MIT © Dmitry Popov
