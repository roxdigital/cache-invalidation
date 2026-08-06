<?php

declare(strict_types=1);

namespace RoxDigital\CacheInvalidation;

use RoxDigital\CacheInvalidation\Graph\DependencyGraph;
use RoxDigital\CacheInvalidation\Recording\DependencyRecorder;
use Statamic\StaticCaching\Cacher;

/**
 * The addon's public API, for dependencies it cannot observe on its own.
 *
 * Entries, terms, globals and forms are recorded automatically — none of this is
 * needed for them. It exists for data that reaches a template from outside
 * Statamic's repositories: an HTTP call, a custom Eloquent model, a file. Nothing
 * observes those reads, and nothing knows when they change, so both halves have to
 * be declared:
 *
 *     // while rendering, or via @cachetags('api:reviews')
 *     CacheTags::add('api:reviews');
 *
 *     // in the job that refetched them
 *     CacheTags::invalidate('api:reviews');
 *
 * Tags are arbitrary strings. Namespacing them like the built-in ones
 * ("api:reviews", not "reviews") keeps them from colliding with a future built-in.
 */
final class CacheTags
{
    public function __construct(
        private readonly DependencyRecorder $recorder,
        private readonly DependencyGraph $graph,
        private readonly Cacher $cacher,
    ) {}

    /**
     * Record tags against the page currently rendering.
     */
    public function add(string ...$tags): void
    {
        $this->recorder->add(...$tags);
    }

    /**
     * Clear every cached URL carrying any of the given tags.
     *
     * Returns the number of URLs cleared.
     */
    public function invalidate(string ...$tags): int
    {
        $urls = $this->urlsFor(...$tags);

        if ($urls === []) {
            return 0;
        }

        config('statamic.static_caching.background_recache', false)
            ? $this->cacher->refreshUrls($urls)
            : $this->cacher->invalidateUrls($urls);

        return count($urls);
    }

    /**
     * The cached URLs carrying any of the given tags, without clearing them.
     *
     * @return list<string>
     */
    public function urlsFor(string ...$tags): array
    {
        $tags = array_values(array_filter($tags, static fn (string $tag): bool => $tag !== ''));

        if ($tags === []) {
            return [];
        }

        // Unlike a content save, this deliberately does not sweep up cached URLs
        // that are absent from the graph. That safety net exists so routine
        // editing can never leave a page stale, and the next content save applies
        // it anyway; folding it in here would make an explicit, targeted call
        // clear the entire cache right after a deploy.
        return $this->graph->urlsFor([...$tags, Tag::OVERFLOW]);
    }
}
