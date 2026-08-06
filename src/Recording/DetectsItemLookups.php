<?php

declare(strict_types=1);

namespace RoxDigital\CacheInvalidation\Recording;

/**
 * Distinguishes "this page renders these specific items" from "this page renders
 * whatever matches".
 *
 * This is the whole basis for targeted invalidation. A query that looks items up
 * by id can only be affected by changes to those items, so it records item tags
 * alone. Any other query can produce a different result set once an item is
 * *created* — an entry the page has never seen and whose id is therefore in no
 * tag set — so it must also record the list tag for its scope.
 *
 * It reproduces automatically what the previous config expressed by hand as
 * `['block' => 'x', 'field' => 'y']` versus `['block' => 'x']`, including for
 * blocks that choose between those modes at runtime.
 */
trait DetectsItemLookups
{
    /**
     * Columns that narrow a result set without changing which items could ever
     * appear in it. A query filtered only by these is still a list query.
     */
    private const NON_DISCRIMINATING = ['id', 'site', 'locale', 'status', 'published'];

    /**
     * True only when the query is pinned to specific ids. Note that an empty
     * where clause is emphatically *not* an id lookup: "everything in this
     * collection" is the broadest list query there is.
     */
    protected function isItemLookup(): bool
    {
        if (empty($this->wheres)) {
            return false;
        }

        $pinnedToIds = false;

        foreach ($this->wheres as $where) {
            // Nested clauses carry no column of their own and imply a query too
            // complex to reason about. Treat as a list query.
            $column = $where['column'] ?? null;

            if ($column === 'id') {
                $pinnedToIds = true;

                continue;
            }

            if (! in_array($column, self::NON_DISCRIMINATING, true)) {
                return false;
            }
        }

        return $pinnedToIds;
    }
}
