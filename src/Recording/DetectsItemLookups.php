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
     * Columns that identify specific items rather than describe a set.
     *
     * `uri` belongs here even though, strictly, a newly created entry could claim
     * an existing URI. Statamic resolves every frontend request through
     * findByUri(), which queries `uri` with no collection scope, so treating it as
     * a set query made every rendered page depend on every collection — any entry
     * save anywhere then cleared the entire cache.
     *
     * The case it gives up is narrow and already covered elsewhere: an entry that
     * takes over a URI has that URL in its own absoluteUrl(), which Statamic
     * invalidates directly. Only a page rendering a *teaser* resolved by someone
     * else's URI could go stale, and it would recover on its next render.
     */
    private const IDENTIFYING_COLUMNS = ['id', 'uri'];

    /**
     * Clause types that pin results to known items. Notably not NotIn or a `!=`
     * operator, which exclude items rather than bounding to them.
     */
    private const BOUNDING_TYPES = ['Basic', 'In'];

    protected function isItemLookup(): bool
    {
        // An empty where clause is emphatically not an item lookup: "everything in
        // this collection" is the broadest list query there is.
        if (empty($this->wheres)) {
            return false;
        }

        $bounded = false;

        foreach ($this->wheres as $where) {
            if (($where['boolean'] ?? 'and') !== 'and') {
                return false;
            }

            if ($this->boundsResults($where)) {
                $bounded = true;
            }
        }

        return $bounded;
    }

    /**
     * @param  array<string, mixed>  $where
     */
    private function boundsResults(array $where): bool
    {
        if (! in_array($where['column'] ?? null, self::IDENTIFYING_COLUMNS, true)) {
            return false;
        }

        if (! in_array($where['type'] ?? null, self::BOUNDING_TYPES, true)) {
            return false;
        }

        return ($where['type'] === 'In') || ($where['operator'] ?? '=') === '=';
    }
}
