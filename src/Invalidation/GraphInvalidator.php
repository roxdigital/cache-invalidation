<?php

declare(strict_types=1);

namespace RoxDigital\CacheInvalidation\Invalidation;

use RoxDigital\CacheInvalidation\CachedUrls;
use RoxDigital\CacheInvalidation\Graph\DependencyGraph;
use RoxDigital\CacheInvalidation\Tag;
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
    /**
     * Whether the current pass should refresh rather than purge.
     *
     * Deliberately ours rather than DefaultInvalidator::$refreshing. That property
     * only exists in newer 6.x releases, and on versions without it `refresh()`
     * duplicates the invalidation logic instead of delegating to `invalidate()` —
     * which would bypass the graph entirely and refresh only the saved item's own
     * URL. Overriding refresh() and tracking the flag here behaves the same on every
     * 6.x, and removes a dependency on a protected member that moved once already.
     */
    private bool $refreshingUrls = false;

    public function __construct(
        Cacher $cacher,
        array|string|null $rules,
        private readonly DependencyGraph $graph,
        private readonly TagResolver $tags,
        private readonly CachedUrls $cached,
    ) {
        // Widely typed because $rules is resolved from config and a host app may
        // legitimately hold any of three shapes there. A config predating the
        // invalidation.rules key, or setting it to null, arrives as null. And
        // Statamic documents the string 'all' as a value in its own right —
        // DefaultInvalidator::invalidate() checks for exactly that — so a stock
        // config would otherwise fatal on a TypeError the moment static caching
        // was switched on.
        //
        // Anything that is not an array becomes one. 'all' means "flush
        // everything on any save", which is the behaviour the graph exists to
        // replace: urls are invalidated individually instead. Passing it through
        // would hand DefaultInvalidator a reason to flush the whole cache behind
        // our back. The rules are unused either way; they exist so the parent
        // stays satisfied.
        parent::__construct($cacher, is_array($rules) ? $rules : []);
    }

    public function refresh($item): void
    {
        if (! config('statamic.static_caching.background_recache', false)) {
            $this->invalidate($item);

            return;
        }

        $previous = $this->refreshingUrls;
        $this->refreshingUrls = true;

        try {
            $this->invalidate($item);
        } finally {
            $this->refreshingUrls = $previous;
        }
    }

    public function invalidate($item): void
    {
        $tags = $this->tags->forItem($item);
        $cached = $this->cached->all();

        $this->clear([
            ...$this->getItemUrls($item),
            ...$tags === [] ? [] : $this->stillCached($this->graph->urlsFor([...$tags, Tag::OVERFLOW]), $cached),
            ...$this->graph->untracked($cached),
        ]);
    }

    /**
     * The graph outlives the cache: a URL keeps its row after its cached copy is
     * gone, and rows are only pruned as they are invalidated. On a site that has
     * been up for a while most of what a tag resolves to is no longer cached —
     * often two thirds of it — and every one of those costs the cacher a lookup
     * that can only miss. Narrowing here is what makes the remaining work match
     * the number of pages that actually have to be cleared.
     *
     * Deliberately not applied to getItemUrls(): those are Statamic's own, may be
     * wildcards, and are not graph rows to begin with.
     *
     * @param  list<string>  $urls
     * @param  list<string>  $cached
     * @return list<string>
     */
    private function stillCached(array $urls, array $cached): array
    {
        // An empty list is ambiguous. It means "nothing is cached" on a site that
        // was just flushed, but it equally means "this cacher cannot be
        // enumerated" — CachedUrls gives up on anything that is not an
        // AbstractCacher, which includes a host app's own cacher. Narrowing
        // against it would then clear nothing at all, the one failure this addon
        // exists to prevent. Hand the full set over and let the cacher decide.
        if ($cached === []) {
            return $urls;
        }

        return array_values(array_intersect($urls, $cached));
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

        $this->refreshingUrls
            ? $this->cacher->refreshUrls($urls)
            : $this->cacher->invalidateUrls($urls);
    }
}
