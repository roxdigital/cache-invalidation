<?php

declare(strict_types=1);

namespace RoxDigital\CacheInvalidation\Graph;

/**
 * Stores the url -> tags graph recorded while pages render.
 *
 * URLs are absolute throughout, matching Cacher::getUrl(). Statamic stores its
 * own URL list relative to the domain, so anything comparing the two has to
 * re-prefix; see CachedUrls.
 */
interface DependencyGraph
{
    /**
     * Replace the recorded tag set for a URL. An empty set removes the URL from
     * the graph entirely, which leaves it untracked and therefore cleared by the
     * next invalidation rather than assumed to depend on nothing.
     *
     * @param  list<string>  $tags
     */
    public function record(string $url, array $tags): void;

    /**
     * @param  list<string>  $tags
     * @return list<string>
     */
    public function urlsFor(array $tags): array;

    /**
     * @return list<string>
     */
    public function tagsFor(string $url): array;

    public function forget(string $url): void;

    /**
     * Of the given URLs, those with no recorded dependencies.
     *
     * The safety net behind every invalidation: a URL that is cached but absent
     * from the graph — cached before the addon was installed, or written while
     * the graph was unreachable — has to be treated as depending on everything,
     * or it would stay stale forever with no symptom. Bounded by the number of
     * cached URLs rather than the size of the graph, because it runs on save.
     *
     * @param  list<string>  $urls
     * @return list<string>
     */
    public function untracked(array $urls): array;

    /**
     * @return list<string>
     */
    public function urls(): array;

    /**
     * Drop every URL that is not in the given set, and the rows behind it.
     *
     * The graph only ever learns about a URL by rendering it, so nothing removes
     * a URL that quietly fell out of the static cache. Left alone the graph grows
     * without bound, and `untracked()` pays for rows describing pages that are
     * no longer cached. Callers pass the URLs that are currently cached; anything
     * else is garbage.
     *
     * @param  list<string>  $keepUrls
     * @return int  Rows removed.
     */
    public function prune(array $keepUrls): int;

    /**
     * Reclaim storage freed by prune() or flush().
     *
     * Separate from both because it is the expensive half: SQLite's DELETE only
     * moves pages onto the freelist, so a graph that has been flushed keeps its
     * old size on disk for ever. A no-op for drivers that manage their own
     * storage.
     */
    public function compact(): void;

    public function flush(): void;

    /**
     * @return array{urls: int, tags: int, rows: int}
     */
    public function stats(): array;
}
