<?php

declare(strict_types=1);

namespace RoxDigital\CacheInvalidation;

use Statamic\Events\CollectionTreeSaved;

/**
 * A tree save changes entry URLs (a move) or their order (a reorder). The block
 * index is keyed on absoluteUrl(), so after a move it maps URLs that no longer
 * exist while the new ones are absent — every later invalidation then targets
 * dead keys. Statamic's own CollectionTreeEntriesMovedOrRemoved purges the moved
 * URLs straight through the cacher, bypassing the invalidator, so nothing else
 * clears the index; and a pure reorder does not dispatch that event at all.
 */
class HandleCollectionTreeSaved
{
    public function __construct(
        private readonly PagebuilderDependencyScanner $pagebuilder,
        private readonly StaticCacheFlusher $cache,
    ) {}

    public function handle(CollectionTreeSaved $event): void
    {
        $this->pagebuilder->clearIndex();

        $handle = $event->tree->collection()->handle();

        if (in_array($handle, config('cache_invalidation.collection_trees_flush_all', []), true)) {
            $this->cache->flush();
        }
    }
}
