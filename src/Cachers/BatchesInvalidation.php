<?php

declare(strict_types=1);

namespace RoxDigital\CacheInvalidation\Cachers;

use Illuminate\Support\Collection;

/**
 * Collapses a bulk invalidation's URL-map writes into one per domain.
 *
 * Statamic keeps every cached URL for a domain in a single cache entry, and
 * AbstractCacher::invalidateUrls() walks the URLs one at a time. Each call reads
 * that entry, and each match calls forgetUrl(), which reads it again and writes
 * the whole thing back. Clearing n URLs from a map of m costs O(n*m): on a site
 * with a few thousand cached URLs the entry is hundreds of kilobytes, every
 * write takes an exclusive lock on one file, and live traffic is rewriting the
 * same entry on each uncached render.
 *
 * That is what the graph makes expensive rather than cheap. Targeted
 * invalidation hands the cacher a long list of individually correct URLs — the
 * whole point — and the per-URL rewrite turns precision into work. A queued
 * invalidation job then runs past its timeout, is killed part-way through the
 * list, and every URL it had not reached yet stays stale. The failure is silent:
 * the editor sees a saved entry and the site keeps serving the old page.
 *
 * Buffering getUrls() for the duration of the call leaves the parent's matching,
 * response deletion and event dispatch exactly as they are — nothing here knows
 * what a cached response looks like — while turning m reads and writes into one
 * read and one write per domain actually touched.
 */
trait BatchesInvalidation
{
    /**
     * Domain => URL map, filled lazily while a batch is in flight. Null whenever
     * no batch is running, which is what every other caller sees.
     *
     * @var array<string, Collection>|null
     */
    private ?array $bufferedUrls = null;

    /**
     * Domains whose buffered map was actually modified. A pass that matches
     * nothing must not write the map back.
     *
     * @var array<string, true>
     */
    private array $dirtyDomains = [];

    /**
     * @param  array  $urls
     * @return void
     */
    public function invalidateUrls($urls)
    {
        $this->whileBuffering(fn () => parent::invalidateUrls($urls));
    }

    /**
     * Refreshing never writes the map — it reads it to find URLs to warm — but it
     * reads it once per URL, so the same buffer removes that too.
     *
     * @param  array  $urls
     * @return void
     */
    public function refreshUrls($urls)
    {
        $this->whileBuffering(fn () => parent::refreshUrls($urls));
    }

    /**
     * @param  string|null  $domain
     * @return Collection
     */
    public function getUrls($domain = null)
    {
        if ($this->bufferedUrls === null) {
            return parent::getUrls($domain);
        }

        return $this->bufferedUrls[$this->bufferKey($domain)] ??= parent::getUrls($domain);
    }

    /**
     * @param  string  $key
     * @param  string|null  $domain
     * @return void
     */
    public function forgetUrl($key, $domain = null)
    {
        if ($this->bufferedUrls === null) {
            parent::forgetUrl($key, $domain);

            return;
        }

        // Collection::forget() mutates, so this updates the buffered map in place
        // and the parent's next getUrls() in the same pass sees the removal.
        $this->getUrls($domain)->forget($key);

        $this->dirtyDomains[$this->bufferKey($domain)] = true;
    }

    private function whileBuffering(callable $callback): void
    {
        // A nested call is already inside a batch. Letting it run against the open
        // buffer keeps one flush at the end instead of writing a half-finished map.
        if ($this->bufferedUrls !== null) {
            $callback();

            return;
        }

        $this->bufferedUrls = [];
        $this->dirtyDomains = [];

        try {
            $callback();
        } finally {
            $buffered = $this->bufferedUrls;
            $dirty = $this->dirtyDomains;

            // Closed before the writes so they, and anything reading the map
            // afterwards, take the ordinary unbuffered path. In a finally block
            // because a throw part-way through still has to persist the deletions
            // already made — the alternative is forgetting them and serving pages
            // whose responses are gone.
            $this->bufferedUrls = null;
            $this->dirtyDomains = [];

            foreach (array_keys($dirty) as $domain) {
                $this->cache->forever($this->getUrlsCacheKey($domain), $buffered[$domain]->all());
            }
        }
    }

    /**
     * @param  string|null  $domain
     */
    private function bufferKey($domain): string
    {
        // Matches how getUrls() and getUrlsCacheKey() resolve a null domain, so a
        // pass that mixes null and explicit domains still shares one buffer entry.
        return $domain ?: $this->getBaseUrl();
    }
}
