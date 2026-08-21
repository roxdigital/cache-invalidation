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

    public function flush(): void;

    /**
     * @return array{urls: int, tags: int, rows: int}
     */
    public function stats(): array;
}
