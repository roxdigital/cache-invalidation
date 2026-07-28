<?php

declare(strict_types=1);

namespace RoxDigital\CacheInvalidation;

use Statamic\Facades\StaticCache;

class StaticCacheFlusher
{
    public function __construct(
        private readonly PagebuilderDependencyScanner $pagebuilder,
    ) {}

    /**
     * Flush the entire static cache the same way `statamic:static:clear` does.
     *
     * Going through the manager rather than the cacher also clears nocache
     * regions, the dedicated static cache store, and cached error pages, so a
     * shared 404 cannot survive a flush with stale layout, nav or globals.
     */
    public function flush(): void
    {
        StaticCache::flush();

        $this->pagebuilder->clearIndex();
    }
}
