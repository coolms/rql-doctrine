<?php

declare(strict_types=1);

namespace CoolMS\RqlDoctrine;

use CoolMS\Rql\FilterNode;
use Doctrine\ORM\QueryBuilder;

/**
 * Resolves a filter field that has NO path through the root entity's ORM
 * metadata.
 *
 * Whitelisting a field is enough when it maps to a column or to a relation the
 * visitor can traverse. It is not enough across a module boundary. A module
 * owning Branches and Teams cannot add a mapped association to `User`, an
 * aggregate another module owns, without exactly the coupling that boundary
 * exists to prevent -- and a codebase that bans cross-module foreign keys has
 * no association to traverse in the first place.
 *
 * So the owning module supplies the predicate itself. It holds the link in its
 * own table behind a plain id column, and constrains the query with a
 * correlated EXISTS keyed on the root id:
 *
 *   EXISTS (SELECT 1 FROM Org\BranchMembership m
 *           WHERE m.userId = u.id AND m.branchId IN (:ids))
 *
 * The boundary is crossed exactly ONCE, at that id. Everything below it --
 * branch, team, membership -- is internal to the contributing module and uses
 * ordinary joins, so arbitrary depth needs no cross-module traversal.
 *
 * Consulted BEFORE `RqlContext::resolve()`, because a contributed field has no
 * resolvable path by definition. It must still reach the whitelist some other
 * way: a field that resolves but was never published is queryable while
 * invisible.
 *
 * The interface names `QueryBuilder`, and that is deliberate rather than a
 * compromise of the AST's backend independence. The AST is the agnostic layer;
 * translating it is per-backend, which is why an in-memory filter and this
 * visitor are separate translators of the same tree. A contributor supplies a
 * fragment of one translation, so it belongs beside the translator it extends.
 * Another backend ships its own contributor contract next to its own visitor,
 * and the grammar and AST stay untouched.
 *
 * The alternative -- an agnostic contract returning matching root ids -- was
 * rejected: it materialises the whole matching set in PHP, which is the exact
 * failure this seam exists to avoid.
 *
 * Implementations are auto-tagged; see PredicateContributorPass::TAG.
 */
interface FilterPredicateContributorInterface
{
    /**
     * Whether this contributor owns `$field` for the entity rooted at
     * `$rootClass`.
     *
     * Keyed on the ROOT CLASS rather than an alias so two entities may
     * publish the same field name without colliding.
     *
     * @param class-string $rootClass
     */
    public function supports(string $rootClass, string $field): bool;

    /**
     * Constrain `$qb` for `$node`.
     *
     * Apply with `andWhere()` and bind parameters namespaced by
     * `$paramIndex` -- several contributed filters may appear in one query.
     * The node is consumed: the visitor will NOT also try to resolve it.
     *
     * @param class-string $rootClass
     * @param string       $entityAlias root alias in `$qb` (`u`, ...)
     */
    public function apply(
        QueryBuilder $qb,
        string $rootClass,
        string $entityAlias,
        FilterNode $node,
        int $paramIndex,
    ): void;
}
