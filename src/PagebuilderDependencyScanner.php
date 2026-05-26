<?php

declare(strict_types=1);

namespace RoxDigital\CacheInvalidation;

use Closure;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Statamic\Entries\Entry;
use Statamic\Facades\Entry as EntryFacade;

class PagebuilderDependencyScanner
{
    private const INDEX_KEY = 'cache_invalidation.block_index';

    public function __construct(
        private readonly PagebuilderBlockResolver $blocks,
    ) {}

    public function urlsForBlocksMatching(Closure $predicate): Collection
    {
        return collect($this->getIndex())
            ->filter(fn (array $blocks): bool => collect($blocks)->contains($predicate))
            ->keys()
            ->values();
    }

    public function clearIndex(): void
    {
        Cache::forget(self::INDEX_KEY);
    }

    /**
     * @return array<string, list<array<string, mixed>>>
     */
    private function getIndex(): array
    {
        return Cache::rememberForever(self::INDEX_KEY, fn (): array => $this->buildIndex());
    }

    /**
     * @return array<string, list<array<string, mixed>>>
     */
    private function buildIndex(): array
    {
        $collections = config('cache_invalidation.pagebuilder_collections', ['pages']);

        return EntryFacade::whereInCollection($collections)
            ->filter(fn (Entry $entry): bool => $this->isPublicPagebuilderEntry($entry))
            ->mapWithKeys(fn (Entry $entry): array => [
                $entry->absoluteUrl() => $this->blocks->resolve($entry, true),
            ])
            ->all();
    }

    private function isPublicPagebuilderEntry(Entry $entry): bool
    {
        if (! $entry->published()) {
            return false;
        }

        return $entry->absoluteUrl() !== null
            && is_array($entry->get('pagebuilder'));
    }
}
