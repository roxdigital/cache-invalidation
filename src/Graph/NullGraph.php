<?php

declare(strict_types=1);

namespace RoxDigital\CacheInvalidation\Graph;

/**
 * Records nothing, so every cached URL is untracked and the safety net clears
 * all of them on any save. Effectively "flush on every save" — useful to rule
 * the addon out while debugging, not a production driver.
 */
final class NullGraph implements DependencyGraph
{
    public function record(string $url, array $tags): void {}

    public function urlsFor(array $tags): array
    {
        return [];
    }

    public function tagsFor(string $url): array
    {
        return [];
    }

    public function forget(string $url): void {}

    /**
     * Everything is untracked, which is what makes this driver behave as "clear
     * the whole cache on every save".
     */
    public function untracked(array $urls): array
    {
        return $urls;
    }

    public function urls(): array
    {
        return [];
    }

    public function flush(): void {}

    public function stats(): array
    {
        return ['urls' => 0, 'tags' => 0, 'rows' => 0];
    }
}
