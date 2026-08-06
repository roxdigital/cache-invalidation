<?php

declare(strict_types=1);

namespace RoxDigital\CacheInvalidation\Invalidation;

use RoxDigital\CacheInvalidation\CachedUrls;
use RoxDigital\CacheInvalidation\Graph\DependencyGraph;
use RoxDigital\CacheInvalidation\Tag;
use Statamic\Contracts\Structures\Nav;
use Statamic\Contracts\Structures\NavTree;
use Statamic\StaticCaching\Cacher;
use Statamic\StaticCaching\DefaultInvalidator;

/**
 * Resolves what to clear from the recorded dependency graph instead of from
 * configuration.
 *
 * Work per save is one indexed lookup plus the deletes themselves. Nothing walks
 * content, which matters because Statamic dispatches invalidation as a queued job
 * and a site may have a single worker — or, with QUEUE_CONNECTION=sync, run it
 * inside the editor's save request.
 *
 * Extends DefaultInvalidator to keep getItemUrls(): the saved item's own URL and
 * its descendants are Statamic's business, not the graph's.
 */
final class GraphInvalidator extends DefaultInvalidator
{
    public function __construct(
        Cacher $cacher,
        array $rules,
        private readonly DependencyGraph $graph,
        private readonly TagResolver $tags,
        private readonly CachedUrls $cached,
    ) {
        parent::__construct($cacher, $rules);
    }

    public function invalidate($item): void
    {
        // Navigation is the one deliberate blunt instrument. A reorder or relabel
        // changes links rendered in shared layout, and no per-page dependency can
        // express that. Clearing every cached URL rather than flushing keeps
        // nocache regions and the graph itself intact, so pages come back without
        // a full re-render storm.
        if ($item instanceof Nav || $item instanceof NavTree) {
            $this->clear($this->cached->all());

            return;
        }

        $tags = $this->tags->forItem($item);

        $this->clear([
            ...$this->getItemUrls($item),
            ...$tags === [] ? [] : $this->graph->urlsFor([...$tags, Tag::OVERFLOW]),
            ...$this->graph->untracked($this->cached->all()),
        ]);
    }

    /**
     * @param  list<string>  $urls
     */
    private function clear(array $urls): void
    {
        $urls = array_values(array_unique(array_filter($urls)));

        if ($urls === []) {
            return;
        }

        // DefaultInvalidator::refresh() flips this before delegating here. v1
        // ignored it and always hard-purged, which silently broke
        // static_caching.background_recache.
        $this->refreshing
            ? $this->cacher->refreshUrls($urls)
            : $this->cacher->invalidateUrls($urls);
    }
}
