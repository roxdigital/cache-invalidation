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
        $relevantFields = $this->indexRelevantFields();

        return EntryFacade::whereInCollection($collections)
            ->filter(fn (Entry $entry): bool => $this->isPublicPagebuilderEntry($entry))
            ->mapWithKeys(fn (Entry $entry): array => [
                $entry->absoluteUrl() => $this->slimBlocks($this->blocks->resolve($entry, true), $relevantFields),
            ])
            ->all();
    }

    /**
     * Returns the field handles that must be kept in the index.
     * Only fields referenced in collection_entry_rules are needed;
     * global/taxonomy predicates only match on block type.
     *
     * @return list<string>
     */
    private function indexRelevantFields(): array
    {
        return collect(config('cache_invalidation.collection_entry_rules', []))
            ->flatMap(fn (mixed $rules): array => is_array($rules)
                ? collect($rules)->pluck('field')->filter()->all()
                : []
            )
            ->unique()
            ->values()
            ->all();
    }

    /**
     * Strips each block down to type + the fields needed for rule matching,
     * discarding rich-text and other large field values that bloat the cache.
     *
     * @param  list<array<string, mixed>>  $blocks
     * @param  list<string>  $relevantFields
     * @return list<array<string, mixed>>
     */
    private function slimBlocks(array $blocks, array $relevantFields): array
    {
        return array_map(function (array $block) use ($relevantFields): array {
            $slim = ['type' => $block['type'] ?? null];

            foreach ($relevantFields as $field) {
                if (array_key_exists($field, $block)) {
                    $slim[$field] = $block[$field];
                }
            }

            return $slim;
        }, $blocks);
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
