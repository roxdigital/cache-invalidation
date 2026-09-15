<?php

declare(strict_types=1);

namespace RoxDigital\CacheInvalidation\Graph;

use Statamic\Events\StaticCacheCleared;

/**
 * Keeps the graph from outliving the cache it describes.
 *
 * Not strictly required for correctness — rows for a URL are replaced whenever it
 * is rendered again, and a row for a URL that is no longer cached only ever leads
 * to invalidating something already gone. One DELETE after a flush is cheaper
 * than carrying the whole graph as garbage.
 *
 * Deliberately not listening to UrlInvalidated as well: that fires once per URL,
 * so pruning there would turn a single nav save into thousands of individual
 * writes on the save path, buying nothing.
 */
final class ClearGraphWhenCacheCleared
{
    public function __construct(
        private readonly DependencyGraph $graph,
    ) {}

    public function handle(StaticCacheCleared $event): void
    {
        $this->graph->flush();
    }
}
