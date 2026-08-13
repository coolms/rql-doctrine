<?php

declare(strict_types=1);

namespace CoolMS\RqlDoctrine;

use CoolMS\Rql\Exception\RqlInvalidOperatorException;
use CoolMS\Rql\FilterNode;
use CoolMS\Rql\FilterOp;
use CoolMS\Rql\RqlContext;
use Doctrine\ORM\QueryBuilder;

/**
 * Applies extras.* JSON field filters to a Doctrine QueryBuilder.
 *
 * Subclasses emit platform-native JSON extraction syntax matching
 * DynamicSchemaManager::Platform/* generated column sources so the
 * query planner can use the indexed v_{name} column transparently.
 */
abstract class AbstractDoctrineJsonVisitor
{
    public function __construct(
        protected readonly DynamicFieldSqlTypeResolver $typeResolver,
    ) {
    }

    public function applyJsonFilter(
        FilterNode $node,
        QueryBuilder $qb,
        RqlContext $ctx,
        int $paramIndex = 0,
    ): void {
        $key = $node->jsonKey();
        $paramName = 'rql_json_' . preg_replace('/[^a-z0-9]/i', '_', $key) . '_' . $paramIndex;
        $sqlType = $this->typeResolver->resolve($ctx->dynamicTypeAlias ?? '', $key);

        // Reject LIKE-family operators on non-text extractions before emitting SQL;
        // otherwise the platform rejects (e.g. "double precision ~~ unknown" on PostgreSQL).
        $isLikeOp = in_array($node->op, [FilterOp::Cn, FilterOp::Bw, FilterOp::Ew], true);
        if ($isLikeOp && 'TEXT' !== $sqlType) {
            throw new RqlInvalidOperatorException($node->op->value, $key, $sqlType);
        }

        $expr = $this->jsonExtract($ctx->entityAlias, $key, $sqlType, $ctx->dynamicTypeAlias);

        match ($node->op) {
            FilterOp::Eq => $qb->andWhere("$expr = :$paramName")
                ->setParameter($paramName, $node->value),

            FilterOp::Ne => $qb->andWhere("$expr != :$paramName")
                ->setParameter($paramName, $node->value),

            FilterOp::Gt => $qb->andWhere("$expr > :$paramName")
                ->setParameter($paramName, $node->value),

            FilterOp::Lt => $qb->andWhere("$expr < :$paramName")
                ->setParameter($paramName, $node->value),

            FilterOp::Ge => $qb->andWhere("$expr >= :$paramName")
                ->setParameter($paramName, $node->value),

            FilterOp::Le => $qb->andWhere("$expr <= :$paramName")
                ->setParameter($paramName, $node->value),

            FilterOp::Cn => $qb->andWhere("LOWER($expr) LIKE :$paramName")
                ->setParameter($paramName, '%' . mb_strtolower((string) $node->value) . '%'),

            FilterOp::Bw => $qb->andWhere("LOWER($expr) LIKE :$paramName")
                ->setParameter($paramName, mb_strtolower((string) $node->value) . '%'),

            FilterOp::Ew => $qb->andWhere("LOWER($expr) LIKE :$paramName")
                ->setParameter($paramName, '%' . mb_strtolower((string) $node->value)),

            FilterOp::Null => $qb->andWhere("$expr IS NULL OR $expr = 'null'"),

            FilterOp::Nn => $qb->andWhere("$expr IS NOT NULL AND $expr != 'null'"),

            FilterOp::In, FilterOp::Ni => $this->applyJsonArrayFilter($node, $qb, $ctx, $paramIndex),
        };
    }

    /**
     * Build an extraction expression suitable for ORDER BY. Uses the same
     * per-platform jsonExtract so sort tracks the indexed generated column.
     */
    public function applyJsonOrderBy(
        string $key,
        string $direction,
        QueryBuilder $qb,
        RqlContext $ctx,
    ): void {
        $sqlType = $this->typeResolver->resolve($ctx->dynamicTypeAlias ?? '', $key);
        $expr = $this->jsonExtract($ctx->entityAlias, $key, $sqlType, $ctx->dynamicTypeAlias);
        $qb->addOrderBy($expr, $direction);
    }

    /**
     * Emit the platform-native JSON extraction expression for one key.
     * Must byte-match the source of the generated column your schema layer
     * creates, or the planner cannot use the v_{key} index.
     *
     * $entityAlias is the dynamic type alias carried on RqlContext (e.g.
     * 'page_variant'); subclasses may use it to check whether that field is
     * declared and emit a direct v_* column reference instead of the source
     * expression. Null when the caller supplied no dynamic context, in which
     * case implementations MUST fall back to the source-expression form.
     */
    abstract protected function jsonExtract(
        string $alias,
        string $key,
        string $sqlType,
        ?string $entityAlias,
    ): string;

    private function applyJsonArrayFilter(
        FilterNode $node,
        QueryBuilder $qb,
        RqlContext $ctx,
        int $paramIndex,
    ): void {
        $key = $node->jsonKey();
        $values = (array) $node->value;
        $sqlType = $this->typeResolver->resolve($ctx->dynamicTypeAlias ?? '', $key);
        $exprs = [];

        foreach ($values as $i => $val) {
            $pName = 'rql_json_' . preg_replace('/[^a-z0-9]/i', '_', $key) . "_{$paramIndex}_{$i}";
            $extract = $this->jsonExtract($ctx->entityAlias, $key, $sqlType, $ctx->dynamicTypeAlias);
            $exprs[] = "$extract = :$pName";
            $qb->setParameter($pName, $val);
        }

        $combined = implode(' OR ', $exprs);
        FilterOp::In === $node->op
            ? $qb->andWhere($combined)
            : $qb->andWhere("NOT ($combined)");
    }
}
