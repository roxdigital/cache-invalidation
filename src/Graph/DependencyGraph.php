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
     * @return list<string>
     */
    public function urls(): array;

    public function flush(): void;

    /**
     * @return array{urls: int, tags: int, rows: int}
     */
    public function stats(): array;
}
