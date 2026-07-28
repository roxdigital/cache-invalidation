<?php

declare(strict_types=1);

namespace RoxDigital\CacheInvalidation;

use Statamic\Events\BlueprintSaved;

class FlushStaticCacheOnFormBlueprintSaved
{
    public function __construct(
        private readonly StaticCacheFlusher $cache,
    ) {}

    public function handle(BlueprintSaved $event): void
    {
        if ($event->blueprint->namespace() !== 'forms') {
            return;
        }

        if (! config('cache_invalidation.forms_flush_all', true)) {
            return;
        }

        $this->cache->flush();
    }
}
