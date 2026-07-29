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
        Cache::forget($this->indexKey());
        Cache::forget(self::INDEX_KEY);
    }

    /**
     * The index only keeps the block fields named in collection_entry_rules
     * (see slimBlocks), so a cached index built under a different rule set is
     * not just stale, it is missing keys the new rules match on. Fingerprinting
     * the config into the key means such an index is never read: without this,
     * adding a field-scoped rule silently matches nothing until something else
     * happens to clear the index.
     */
    private function indexKey(): string
    {
        $fingerprint = md5(serialize([
            config('cache_invalidation.collection_entry_rules', []),
            config('cache_invalidation.pagebuilder_collections', ['pages']),
        ]));

        return self::INDEX_KEY.'.'.$fingerprint;
    }

    /**
     * @return array<string, list<array<string, mixed>>>
     */
    private function getIndex(): array
    {
        return Cache::rememberForever($this->indexKey(), fn (): array => $this->buildIndex());
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
     * Returns the block field handles that must be kept in the index.
     *
     * Only 'block' rules match against indexed blocks; global and taxonomy
     * predicates match on block type alone, and the 'field' of a 'collection'
     * rule names a field on an entry rather than on a block.
     *
     * @return list<string>
     */
    private function indexRelevantFields(): array
    {
        return collect(config('cache_invalidation.collection_entry_rules', []))
            ->flatMap(fn (mixed $rules): array => is_array($rules)
                ? collect($rules)
                    ->filter(fn (mixed $rule): bool => is_array($rule) && isset($rule['block'], $rule['field']))
                    ->pluck('field')
                    ->all()
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
