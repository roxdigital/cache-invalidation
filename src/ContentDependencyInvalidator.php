<?php

declare(strict_types=1);

namespace RoxDigital\CacheInvalidation;

use Illuminate\Support\Collection;
use Statamic\Entries\Entry;
use Statamic\Globals\Variables;
use Statamic\StaticCaching\Cacher;
use Statamic\StaticCaching\DefaultInvalidator;
use Statamic\Structures\Nav;
use Statamic\Structures\NavTree;
use Statamic\Taxonomies\LocalizedTerm;

class ContentDependencyInvalidator extends DefaultInvalidator
{
    public function __construct(
        Cacher $cacher,
        array $rules,
        private readonly PagebuilderDependencyScanner $pagebuilder,
        private readonly EntryReferenceExtractor $references,
    ) {
        parent::__construct($cacher, $rules);
    }

    public function invalidate($item): void
    {
        if ($this->shouldFlushAll($item)) {
            $this->cacher->flush();
            $this->pagebuilder->clearIndex();

            return;
        }

        $urls = $this->urlsFor($item)
            ->filter()
            ->unique()
            ->values();

        $this->cacher->invalidateUrls($urls->all());

        if ($item instanceof Entry && $this->entryAffectsPageIndex($item)) {
            $this->pagebuilder->clearIndex();
        }
    }

    private function entryAffectsPageIndex(Entry $entry): bool
    {
        $pagebuilderCollections = config('cache_invalidation.pagebuilder_collections', ['pages']);

        return in_array($entry->collectionHandle(), $pagebuilderCollections, true)
            || $entry->collectionHandle() === 'reusable_blocks';
    }

    private function shouldFlushAll(mixed $item): bool
    {
        if ($item instanceof Variables) {
            return in_array(
                $item->globalSet()->handle(),
                config('cache_invalidation.globals_flush_all', []),
                true,
            );
        }

        if ($item instanceof Nav) {
            return in_array(
                $item->handle(),
                config('cache_invalidation.navs_flush_all', []),
                true,
            );
        }

        if ($item instanceof NavTree) {
            return in_array(
                $item->structure()->handle(),
                config('cache_invalidation.navs_flush_all', []),
                true,
            );
        }

        return false;
    }

    private function urlsFor(mixed $item): Collection
    {
        if ($item instanceof Variables) {
            return $this->urlsForGlobal($item);
        }

        if ($item instanceof Entry) {
            return $this->urlsForEntry($item);
        }

        if ($item instanceof LocalizedTerm) {
            return $this->urlsForTaxonomyTerm($item);
        }

        return collect();
    }

    private function urlsForGlobal(Variables $variables): Collection
    {
        $handle = $variables->globalSet()->handle();
        $blockTargets = config('cache_invalidation.global_target_blocks', []);
        $urls = $this->configuredUrls('global_urls', $handle);

        if (! isset($blockTargets[$handle])) {
            return $urls;
        }

        $types = (array) $blockTargets[$handle];

        return $urls->merge($this->pagebuilder->urlsForBlocksMatching(
            fn (array $block): bool => in_array($block['type'] ?? null, $types, true),
        ));
    }

    private function urlsForEntry(Entry $entry): Collection
    {
        $collection = $entry->collectionHandle();
        $rules = config('cache_invalidation.collection_entry_rules', []);
        $urls = $this->ownUrl($entry)
            ->merge($this->configuredUrls('collection_urls', $collection));

        if (! array_key_exists($collection, $rules)) {
            return $urls->merge($this->customEntryUrls($entry));
        }

        $rule = $rules[$collection];

        if ($rule === 'all') {
            return $this->allCachedUrls()->merge($urls);
        }

        $entryId = $entry->id();

        return $urls->merge(
            $this->pagebuilder->urlsForBlocksMatching(
                fn (array $block): bool => $this->blockMatchesAnyRule($block, (array) $rule, $entryId),
            ),
        );
    }

    private function urlsForTaxonomyTerm(LocalizedTerm $term): Collection
    {
        $taxonomy = $term->taxonomyHandle();
        $blockTargets = config('cache_invalidation.taxonomy_target_blocks', []);
        $urls = $this->configuredUrls('taxonomy_urls', $taxonomy);

        if (! isset($blockTargets[$taxonomy])) {
            return $urls;
        }

        $types = (array) $blockTargets[$taxonomy];

        return $urls->merge($this->pagebuilder->urlsForBlocksMatching(
            fn (array $block): bool => in_array($block['type'] ?? null, $types, true),
        ));
    }

    private function ownUrl(Entry $entry): Collection
    {
        $url = $entry->absoluteUrl();

        return $url ? collect([$url]) : collect();
    }

    /**
     * Override in a site-specific subclass when a collection cannot be expressed
     * with config-driven block and field-reference rules.
     */
    protected function customEntryUrls(Entry $entry): Collection
    {
        return collect();
    }

    /**
     * @param  array<string, mixed>  $block
     * @param  list<array{block: string, field?: string}>  $rules
     */
    private function blockMatchesAnyRule(array $block, array $rules, string $entryId): bool
    {
        foreach ($rules as $rule) {
            $blockType = $rule['block'];

            if (($block['type'] ?? null) !== $blockType) {
                continue;
            }

            if (! isset($rule['field'])) {
                return true;
            }

            if ($this->valueReferencesEntry($block[$rule['field']] ?? null, $entryId)) {
                return true;
            }
        }

        return false;
    }

    private function valueReferencesEntry(mixed $value, string $entryId): bool
    {
        return in_array($entryId, $this->references->extract($value), true);
    }

    private function configuredUrls(string $configKey, string $handle): Collection
    {
        return collect((array) config("cache_invalidation.{$configKey}.{$handle}", []))
            ->filter()
            ->values();
    }

    private function allCachedUrls(): Collection
    {
        return $this->cacher->getUrls()->filter()->values();
    }
}
