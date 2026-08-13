<?php

declare(strict_types=1);

namespace CoolMS\RqlDoctrine;

use CoolMS\Rql\AndNode;
use CoolMS\Rql\Exception\RqlInvalidValueException;
use CoolMS\Rql\FilterNode;
use CoolMS\Rql\FilterOp;
use CoolMS\Rql\OrNode;
use CoolMS\Rql\RqlContext;
use CoolMS\Rql\RqlQuery;
use CoolMS\Rql\RqlResult;
use CoolMS\Rql\SortDirection;
use Doctrine\DBAL\Exception as DbalException;
use Doctrine\ORM\Query\Expr\Andx;
use Doctrine\ORM\Query\Expr\Comparison;
use Doctrine\ORM\Query\Expr\Func;
use Doctrine\ORM\Query\Expr\Orx;
use Doctrine\ORM\QueryBuilder;
use InvalidArgumentException;

/**
 * Applies an RqlQuery to a Doctrine QueryBuilder.
 *
 * Handles:
 *   - Standard column filters (eq/ne/gt/lt/ge/le/cn/bw/ew/in/ni/null/nn)
 *   - OR groups (OrNode)
 *   - extras.* JSON fields (delegated to AbstractDoctrineJsonVisitor)
 *   - Transparent field mapping: virtual names (e.g. 'price') resolved to
 *     extras.* paths via RqlContext::fieldMap before routing
 *   - Sort + pagination
 *   - Count query for totalItems
 */
final readonly class DoctrineRqlVisitor
{
    /**
     * @param iterable<FilterPredicateContributorInterface> $predicateContributors
     */
    public function __construct(
        private AbstractDoctrineJsonVisitor $jsonVisitor,
        private iterable $predicateContributors = [],
    ) {
    }

    /**
     * Apply RQL query to QueryBuilder and return paginated results.
     */
    public function apply(
        RqlQuery $query,
        QueryBuilder $qb,
        RqlContext $ctx,
    ): RqlResult {
        // Clone QB for count (before pagination is applied)
        $countQb = clone $qb;
        // Count query must never carry ORDER BY -- it conflicts with the
        // COUNT(DISTINCT ...) aggregate selected below. Any pre-set sort on
        // the base QB is caller intent for the data query only.
        $countQb->resetDQLPart('orderBy');

        $this->applyFilters($query, $qb, $ctx);
        $this->applySort($query, $qb, $ctx);

        // Apply pagination
        $qb->setFirstResult($query->offset())
            ->setMaxResults($query->limit);

        try {
            $items = $qb->getQuery()->getResult();

            // Count total (same filters, no pagination)
            $this->applyFilters($query, $countQb, $ctx);
            $aliases = $countQb->getRootAliases();
            $rootAlias = $aliases[0] ?? $ctx->entityAlias;
            $totalItems = (int) $countQb
                ->select("COUNT(DISTINCT $rootAlias.id)")
                ->getQuery()
                ->getSingleScalarResult();
        } catch (DbalException $e) {
            // Invalid user-supplied filter value (e.g. non-UUID string on a
            // uuid-typed column). Surface as 400 Bad Request instead of a
            // generic 500 from the SQL driver.
            if (str_contains($e->getMessage(), 'Invalid text representation')) {
                throw new RqlInvalidValueException('One or more filter values are not valid for the target column type.', previous: $e);
            }

            throw $e;
        }

        return new RqlResult(
            items: $items,
            totalItems: $totalItems,
            page: $query->page,
            limit: $query->limit,
        );
    }

    /**
     * Apply filters only (without count/pagination) -- for use in sub-queries.
     */
    public function applyFilters(
        RqlQuery $query,
        QueryBuilder $qb,
        RqlContext $ctx,
    ): void {
        $paramIndex = 0;
        foreach ($query->filters as $node) {
            if ($node instanceof OrNode || $node instanceof AndNode) {
                // Boolean group (possibly nested) -- build one composite expr and AND it on.
                $expr = $this->buildGroupExpr($node, $qb, $ctx, $paramIndex * 1000);
                if (null !== $expr) {
                    $qb->andWhere($expr);
                }
            } else {
                $this->applyFilterNode($node, $qb, $ctx, $paramIndex);
            }
            ++$paramIndex;
        }
    }

    /**
     * ORDER BY resolves through {@see RqlContext::resolveSort}, NOT `resolve()`:
     * sortable and filterable are independent capabilities in the grid configs
     * that build these contexts, and a column can advertise sorting while having
     * no filter operator at all (a computed projection such as the group list's
     * `memberCount`). Resolving sort against the FILTER whitelist rejected those
     * with a 400 the moment a user clicked the header.
     */
    public function applySort(
        RqlQuery $query,
        QueryBuilder $qb,
        RqlContext $ctx,
    ): void {
        foreach ($query->sort as $node) {
            $resolved = $ctx->resolveSort($node->field);
            $dir = SortDirection::Desc === $node->direction ? 'DESC' : 'ASC';

            if (str_starts_with($resolved, 'extras.')) {
                $this->jsonVisitor->applyJsonOrderBy(substr($resolved, 7), $dir, $qb, $ctx);
            } else {
                $qb->addOrderBy($resolved, $dir);
            }
        }
    }

    /**
     * Recursively build a Doctrine Andx/Orx expression for a boolean group,
     * supporting arbitrary nesting (Persvr RQL `and(...)`/`or(...)`). A leaf
     * FilterNode goes through {@see buildExpr} (scalar-only; an extras.* JSON
     * leaf inside a group is unsupported and skipped -- the same constraint the
     * OR path has always had). Returns null when the group yields no addable
     * expression (so the caller can omit an empty WHERE fragment).
     */
    private function buildGroupExpr(
        OrNode|AndNode $node,
        QueryBuilder $qb,
        RqlContext $ctx,
        int $base,
    ): Andx|Orx|null {
        $composite = $node instanceof OrNode ? $qb->expr()->orX() : $qb->expr()->andX();

        foreach ($node->nodes as $i => $child) {
            $expr = $child instanceof FilterNode
                ? $this->buildExpr($child, $qb, $ctx, $base + $i)
                : $this->buildGroupExpr($child, $qb, $ctx, ($base + $i + 1) * 1000);
            if (null !== $expr) {
                $composite->add($expr);
            }
        }

        return $composite->count() > 0 ? $composite : null;
    }

    /**
     * Hand `$node` to the contributor that owns it, if any.
     *
     * Returns true when a contributor consumed the node, so the caller stops.
     * The FIRST match wins and iteration stops -- two modules claiming the same
     * field on the same root is a wiring mistake, and silently ANDing both
     * predicates would turn it into a data bug instead of a visible one.
     */
    private function applyContributedPredicate(
        FilterNode $node,
        QueryBuilder $qb,
        RqlContext $ctx,
        int $paramIndex,
    ): bool {
        $roots = $qb->getRootEntities();
        if ([] === $roots) {
            return false;
        }
        $rootClass = $roots[0];

        foreach ($this->predicateContributors as $contributor) {
            if (!$contributor->supports($rootClass, $node->field)) {
                continue;
            }
            $contributor->apply($qb, $rootClass, $ctx->entityAlias, $node, $paramIndex);

            return true;
        }

        return false;
    }

    private function applyFilterNode(
        FilterNode $node,
        QueryBuilder $qb,
        RqlContext $ctx,
        int $paramIndex,
    ): void {
        // A contributed predicate is consulted BEFORE resolution: a field owned
        // by another module has no path through this entity's metadata by
        // definition, so resolving it would throw.
        if ($this->applyContributedPredicate($node, $qb, $ctx, $paramIndex)) {
            return;
        }

        // Always resolve through context first: enforces whitelist and applies
        // transparent field mapping (e.g. 'price' mapped to 'extras.price').
        $resolved = $ctx->resolve($node->field);

        if (str_starts_with($resolved, 'extras.')) {
            // Route to JSON visitor. If the field was transparently mapped
            // (virtual name mapped to extras.*), create a new node with the resolved
            // field so that jsonKey() returns the correct extras key.
            $resolvedNode = $resolved !== $node->field
                ? new FilterNode($resolved, $node->op, $node->value)
                : $node;
            $this->jsonVisitor->applyJsonFilter($resolvedNode, $qb, $ctx, $paramIndex);

            return;
        }

        // Relation traversal: resolved path like 'identifiers.value' or 'groups.id'.
        // Must not be confused with alias-prefixed QB expressions like 'u.isActive'
        // (a known join alias). A genuine relation path becomes a correlated EXISTS.
        if ($this->isRelationPath($resolved, $qb)) {
            $this->applyRelationFilter($node, $resolved, $qb, $ctx, $paramIndex);

            return;
        }

        $column = $resolved;
        $paramName = 'rql_' . preg_replace('/[^a-z0-9]/i', '_', $node->field) . '_' . $paramIndex;

        match ($node->op) {
            FilterOp::Eq => $qb->andWhere("$column = :$paramName")->setParameter($paramName, $node->value),
            FilterOp::Ne => $qb->andWhere("$column != :$paramName")->setParameter($paramName, $node->value),
            FilterOp::Gt => $qb->andWhere("$column > :$paramName")->setParameter($paramName, $node->value),
            FilterOp::Lt => $qb->andWhere("$column < :$paramName")->setParameter($paramName, $node->value),
            FilterOp::Ge => $qb->andWhere("$column >= :$paramName")->setParameter($paramName, $node->value),
            FilterOp::Le => $qb->andWhere("$column <= :$paramName")->setParameter($paramName, $node->value),
            FilterOp::Cn => $qb->andWhere("LOWER($column) LIKE :$paramName")->setParameter($paramName, '%' . mb_strtolower((string) $node->value) . '%'),
            FilterOp::Bw => $qb->andWhere("LOWER($column) LIKE :$paramName")->setParameter($paramName, mb_strtolower((string) $node->value) . '%'),
            FilterOp::Ew => $qb->andWhere("LOWER($column) LIKE :$paramName")->setParameter($paramName, '%' . mb_strtolower((string) $node->value)),
            FilterOp::Null => $qb->andWhere("$column IS NULL"),
            FilterOp::Nn => $qb->andWhere("$column IS NOT NULL"),
            FilterOp::In => $qb->andWhere("$column IN (:$paramName)")->setParameter($paramName, (array) $node->value),
            FilterOp::Ni => $qb->andWhere("$column NOT IN (:$paramName)")->setParameter($paramName, (array) $node->value),
        };
    }

    /**
     * Build a correlated EXISTS subquery for a resolved dot-notation relation path.
     *
     * @param string $resolvedField The resolved DB path (e.g. 'identifiers.value', 'groups.id').
     *                              This may differ from $node->field when a field alias
     *                              (ColumnConfig::filterField) has been applied by RqlContext.
     */
    private function applyRelationFilter(
        FilterNode $node,
        string $resolvedField,
        QueryBuilder $qb,
        RqlContext $ctx,
        int $paramIndex,
    ): void {
        $qb->andWhere($this->buildRelationExists($node, $resolvedField, $qb, $ctx, $paramIndex));
    }

    /**
     * Whether a resolved field path denotes a Doctrine relation to traverse via
     * a correlated EXISTS -- i.e. it contains a dot whose prefix is NOT a known
     * root/join alias (those, e.g. 'u.isActive' or 'p.firstName', are plain
     * alias-qualified columns and handled inline).
     */
    private function isRelationPath(string $resolved, QueryBuilder $qb): bool
    {
        if (!str_contains($resolved, '.')) {
            return false;
        }

        $prefix = substr($resolved, 0, (int) strpos($resolved, '.'));
        $knownAliases = $qb->getRootAliases();
        foreach ($qb->getDQLPart('join') as $joinGroup) {
            foreach ($joinGroup as $join) {
                $knownAliases[] = $join->getAlias();
            }
        }

        return !in_array($prefix, $knownAliases, true);
    }

    /**
     * Build a correlated EXISTS subquery string for a resolved dot-notation
     * relation path, binding its parameter on $qb. Returned as a raw DQL
     * fragment so BOTH the AND path ({@see applyFilterNode}) and the OR path
     * ({@see buildExpr}, via Orx/Andx::add) can compose it -- so relation
     * filters (e.g. `identifiers.value`) work inside boolean groups, not only
     * at the top level.
     *
     * @param string $resolvedField The resolved DB path (e.g. 'identifiers.value', 'groups.id').
     *                              This may differ from $node->field when a field alias
     *                              (ColumnConfig::filterField) has been applied by RqlContext.
     */
    private function buildRelationExists(
        FilterNode $node,
        string $resolvedField,
        QueryBuilder $qb,
        RqlContext $ctx,
        int $paramIndex,
    ): string {
        // Every segment but the last is a relation to traverse; the last is
        // the scalar column being compared. `isRelationPath()` guarantees at
        // least one dot, so `$relations` is never empty.
        $segments = explode('.', $resolvedField);
        $column = (string) array_pop($segments);
        $relations = $segments;

        $em = $qb->getEntityManager();
        $currentClass = $qb->getRootEntities()[0];
        $parentAlias = $ctx->entityAlias;
        $paramName = 'rql_' . preg_replace('/[^a-z0-9]/i', '_', $resolvedField) . '_' . $paramIndex;

        // One EXISTS per hop, each correlated to the alias of the hop above
        // it. DQL permits an inner subquery to reference an outer alias, so
        // `a.b.c` becomes EXISTS(... EXISTS(... c op :p)) rather than the
        // invalid `alias.b.c` column expression a single split emits.
        $opens = [];
        foreach ($relations as $hop => $relation) {
            $meta = $em->getClassMetadata($currentClass);
            if (!$meta->hasAssociation($relation)) {
                throw new InvalidArgumentException(sprintf("RQL relation filter: '%s' is not a Doctrine association on '%s' (hop %d of path '%s'). Resolved from field: '%s'.", $relation, $currentClass, $hop + 1, $resolvedField, $node->field));
            }

            $targetClass = $meta->getAssociationTargetClass($relation);
            $alias = $this->relationHopAlias($relation, $paramIndex, $hop);

            if ($meta->isCollectionValuedAssociation($relation)) {
                $joinCond = "{$alias} MEMBER OF {$parentAlias}.{$relation}";
            } else {
                $mappedBy = $meta->getAssociationMapping($relation)->mappedBy ?? null;

                $joinCond = (null !== $mappedBy && '' !== $mappedBy)
                    ? "{$alias}.{$mappedBy} = {$parentAlias}"
                    : "{$alias} = {$parentAlias}.{$relation}";
            }

            $opens[] = "EXISTS (SELECT 1 FROM {$targetClass} {$alias} WHERE {$joinCond} AND ";
            $parentAlias = $alias;
            $currentClass = $targetClass;
        }

        [$colExpr, $hasParam] = $this->buildRelationConditionExpr($node, $parentAlias, $column, $paramName);

        if ($hasParam) {
            $qb->setParameter($paramName, $this->resolveRelationParamValue($node));
        }

        return implode('', $opens) . $colExpr . str_repeat(')', count($opens));
    }

    /**
     * Subquery alias for one hop of a relation path.
     *
     * Hop 0 keeps the single-hop shape verbatim, so DQL that existed before
     * multi-hop support is byte-identical to what it was. Deeper hops append
     * their index, so a self-referencing path (`parent.parent.name`) cannot
     * collide with itself.
     */
    private function relationHopAlias(string $relation, int $paramIndex, int $hop): string
    {
        $base = 'rql_t_' . preg_replace('/[^a-z0-9]/i', '_', $relation) . '_' . $paramIndex;

        return 0 === $hop ? $base : $base . '_' . $hop;
    }

    /**
     * Build the condition expression fragment for the EXISTS subquery.
     *
     * Returns [exprString, hasParam] -- hasParam=false for IS NULL / IS NOT NULL.
     *
     * @return array{string, bool}
     */
    private function buildRelationConditionExpr(
        FilterNode $node,
        string $tAlias,
        string $subfield,
        string $paramName,
    ): array {
        $col = "{$tAlias}.{$subfield}";
        $colLower = "LOWER({$col})";

        return match ($node->op) {
            FilterOp::Cn => ["{$colLower} LIKE :{$paramName}", true],
            FilterOp::Bw => ["{$colLower} LIKE :{$paramName}", true],
            FilterOp::Ew => ["{$colLower} LIKE :{$paramName}", true],
            FilterOp::Eq => ["{$col} = :{$paramName}", true],
            FilterOp::Ne => ["{$col} != :{$paramName}", true],
            FilterOp::Gt => ["{$col} > :{$paramName}", true],
            FilterOp::Lt => ["{$col} < :{$paramName}", true],
            FilterOp::Ge => ["{$col} >= :{$paramName}", true],
            FilterOp::Le => ["{$col} <= :{$paramName}", true],
            FilterOp::In => ["{$col} IN (:{$paramName})", true],
            FilterOp::Ni => ["{$col} NOT IN (:{$paramName})", true],
            FilterOp::Null => ["{$col} IS NULL", false],
            FilterOp::Nn => ["{$col} IS NOT NULL", false],
        };
    }

    private function resolveRelationParamValue(FilterNode $node): mixed
    {
        return match ($node->op) {
            FilterOp::Cn => '%' . mb_strtolower((string) $node->value) . '%',
            FilterOp::Bw => mb_strtolower((string) $node->value) . '%',
            FilterOp::Ew => '%' . mb_strtolower((string) $node->value),
            FilterOp::In, FilterOp::Ni => (array) $node->value,
            default => $node->value,
        };
    }

    /**
     * Build a Doctrine expression fragment for use inside an OR group.
     *
     * Returns a Comparison / Func / raw-string expression (all accepted by
     * Doctrine's Orx::add) for EVERY scalar operator, or null ONLY for extras.*
     * JSON fields (JSON filters in OR groups are not supported and are routed
     * through the dedicated JSON visitor on the AND path instead).
     *
     * Previously this returned null for in/ni/null/nn as well, and
     * {@see buildGroupExpr} skips null children -- so an OR clause like
     * `status in (1,2) | priority gt 3` silently DROPPED the `in` alternative
     * (narrowing the result set), and `status in (1,2) | deletedAt nn` dropped
     * BOTH, leaving the OR clause empty so NO filter was applied at all
     * (returning unfiltered rows). The match below is now exhaustive.
     */
    private function buildExpr(
        FilterNode $node,
        QueryBuilder $qb,
        RqlContext $ctx,
        int $paramIndex,
    ): Comparison|Func|string|null {
        $resolved = $ctx->resolve($node->field);

        if (str_starts_with($resolved, 'extras.')) {
            return null; // JSON filters in OR groups are not supported
        }

        // Relation traversal (e.g. 'identifiers.value') -- return the correlated
        // EXISTS fragment so it composes into the OR/AND group, matching the AND
        // path. Doctrine's Orx/Andx::add() accepts a raw string.
        if ($this->isRelationPath($resolved, $qb)) {
            return $this->buildRelationExists($node, $resolved, $qb, $ctx, $paramIndex);
        }

        $column = $resolved;
        $paramName = 'rql_' . preg_replace('/[^a-z0-9]/i', '_', $node->field) . '_' . $paramIndex;

        // Bind parameter value (case-folded for LIKE; array for IN/NI; none for NULL/NN)
        match ($node->op) {
            FilterOp::Cn => $qb->setParameter($paramName, '%' . mb_strtolower((string) $node->value) . '%'),
            FilterOp::Bw => $qb->setParameter($paramName, mb_strtolower((string) $node->value) . '%'),
            FilterOp::Ew => $qb->setParameter($paramName, '%' . mb_strtolower((string) $node->value)),
            FilterOp::In,
            FilterOp::Ni => $qb->setParameter($paramName, (array) $node->value),
            FilterOp::Null,
            FilterOp::Nn => null,
            default => $qb->setParameter($paramName, $node->value),
        };

        // Every operator yields an OR-addable expression, so no alternative is
        // silently dropped (IN/NI via Expr\Func, NULL/NN as literal fragments).
        return match ($node->op) {
            FilterOp::Eq => $qb->expr()->eq($column, ":$paramName"),
            FilterOp::Ne => $qb->expr()->neq($column, ":$paramName"),
            FilterOp::Gt => $qb->expr()->gt($column, ":$paramName"),
            FilterOp::Lt => $qb->expr()->lt($column, ":$paramName"),
            FilterOp::Ge => $qb->expr()->gte($column, ":$paramName"),
            FilterOp::Le => $qb->expr()->lte($column, ":$paramName"),
            FilterOp::Cn, FilterOp::Bw, FilterOp::Ew => $qb->expr()->like("LOWER($column)", ":$paramName"),
            FilterOp::In => $qb->expr()->in($column, ":$paramName"),
            FilterOp::Ni => $qb->expr()->notIn($column, ":$paramName"),
            FilterOp::Null => "$column IS NULL",
            FilterOp::Nn => "$column IS NOT NULL",
        };
    }
}
