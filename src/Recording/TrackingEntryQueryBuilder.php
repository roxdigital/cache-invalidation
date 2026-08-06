<?php

declare(strict_types=1);

namespace RoxDigital\CacheInvalidation\Recording;

use Statamic\Facades\Collection;
use Statamic\Stache\Query\EntryQueryBuilder;
use Statamic\Stache\Stores\Store;

/**
 * Records which entries and collections a render actually read.
 *
 * The two overrides are chosen for where the base class dispatches, not for
 * where the API reads best:
 *
 * - getFilteredKeys() is reached by every read path, including count() and
 *   pluck(), which bypass get() entirely in Stache\Query\Builder. Overriding
 *   get() alone would silently miss them.
 * - getItems() is reached only by get(), and only with keys that have already
 *   had limit and offset applied — so item tags describe what was rendered
 *   rather than everything that matched.
 */
final class TrackingEntryQueryBuilder extends EntryQueryBuilder
{
    use DetectsItemLookups;

    public function __construct(
        Store $store,
        private readonly DependencyRecorder $recorder,
    ) {
        parent::__construct($store);
    }

    protected function getFilteredKeys()
    {
        if (! $this->isItemLookup()) {
            // where('collection', ...) never lands in $wheres — EntryQueryBuilder
            // intercepts it into $collections first — so isItemLookup() is not
            // fooled by it. An unscoped query spans every collection.
            $this->recorder->collections($this->collections ?: Collection::handles());
        }

        return parent::getFilteredKeys();
    }

    protected function getItems($keys)
    {
        $items = parent::getItems($keys);

        $this->recorder->entries($items->map->id());

        return $items;
    }
}
