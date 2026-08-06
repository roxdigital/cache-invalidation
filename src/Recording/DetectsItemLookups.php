<?php

declare(strict_types=1);

namespace RoxDigital\CacheInvalidation\Recording;

/**
 * Distinguishes "this page renders these specific items" from "this page renders
 * whatever matches".
 *
 * This is the whole basis for targeted invalidation. The question is only ever
 * whether an item created *later* could appear in this query's results. If it
 * could, the page must react to saves of items it has never seen, which item tags
 * cannot express — only a list tag can.
 *
 * The test is set-bounding, not a list of columns believed to be harmless: a query
 * carrying an AND-ed equality clause on `id` can only ever return a subset of
 * those ids. Every other clause ANDed alongside it — a status filter, a site
 * filter, the nested per-collection clauses Statamic's whereStatus() adds — can
 * only narrow that set further, never widen it. A top-level OR is the one thing
 * that can admit rows from outside, so it disqualifies the query.
 *
 * Getting this wrong in the permissive direction under-invalidates, which is the
 * one failure this addon must not have. Getting it wrong the other way merely
 * clears more pages than necessary.
 */
trait DetectsItemLookups
{
    /**
     * Clause types that pin results to a known set of ids. Notably not NotIn or a
     * `!=` operator, which exclude ids rather than bounding to them.
     */
    private const BOUNDING_TYPES = ['Basic', 'In'];

    protected function isItemLookup(): bool
    {
        // An empty where clause is emphatically not an id lookup: "everything in
        // this collection" is the broadest list query there is.
        if (empty($this->wheres)) {
            return false;
        }

        $bounded = false;

        foreach ($this->wheres as $where) {
            if (($where['boolean'] ?? 'and') !== 'and') {
                return false;
            }

            if ($this->boundsResultsToIds($where)) {
                $bounded = true;
            }
        }

        return $bounded;
    }

    /**
     * @param  array<string, mixed>  $where
     */
    private function boundsResultsToIds(array $where): bool
    {
        if (($where['column'] ?? null) !== 'id') {
            return false;
        }

        if (! in_array($where['type'] ?? null, self::BOUNDING_TYPES, true)) {
            return false;
        }

        return ($where['type'] === 'In') || ($where['operator'] ?? '=') === '=';
    }
}
