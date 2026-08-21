<?php

declare(strict_types=1);

namespace RoxDigital\CacheInvalidation;

use Statamic\StaticCaching\Cacher;
use Statamic\StaticCaching\Cachers\AbstractCacher;

/**
 * Enumerates everything currently in the static cache, as absolute URLs.
 *
 * Statamic keys its URL list per domain and stores the paths relative to it, so
 * reconstituting absolute URLs means walking the domain list rather than reading
 * a single key. Needed by the untracked-URL safety net, which compares what is
 * cached against what the graph knows about.
 */
final class CachedUrls
{
    public function __construct(
        private readonly Cacher $cacher,
    ) {}

    /**
     * @return list<string>
     */
    public function all(): array
    {
        if (! $this->cacher instanceof AbstractCacher) {
            return [];
        }

        $domains = $this->cacher->getDomains();

        if ($domains->isEmpty()) {
            $domains = collect([$this->cacher->getBaseUrl()]);
        }

        return $domains
            ->flatMap(fn (string $domain): array => $this->cacher
                ->getUrls($domain)
                ->map(fn (string $url): string => $domain.$url)
                ->values()
                ->all())
            ->filter()
            ->unique()
            ->values()
            ->all();
    }
}
