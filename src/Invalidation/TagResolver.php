<?php

declare(strict_types=1);

namespace RoxDigital\CacheInvalidation\Invalidation;

use RoxDigital\CacheInvalidation\Tag;
use Statamic\Contracts\Assets\Asset;
use Statamic\Contracts\Entries\Collection;
use Statamic\Contracts\Entries\Entry;
use Statamic\Contracts\Forms\Form;
use Statamic\Contracts\Globals\Variables;
use Statamic\Contracts\Structures\Nav;
use Statamic\Contracts\Structures\NavTree;
use Statamic\Structures\CollectionTree;
use Statamic\Taxonomies\LocalizedTerm;
use Statamic\Taxonomies\Term;

/**
 * The mirror of the recorders: which tags a saved item invalidates.
 *
 * An entry yields both its item tag and its collection's list tag. The list tag
 * is what makes a newly created entry appear on pages that have never seen it —
 * their recorded dependencies cannot contain an id that did not exist yet.
 */
final class TagResolver
{
    /**
     * @return list<string>
     */
    public function forItem(mixed $item): array
    {
        return match (true) {
            $item instanceof Entry => array_values(array_filter([
                Tag::entry((string) $item->id()),
                $item->collectionHandle() ? Tag::collection((string) $item->collectionHandle()) : null,
            ])),

            $item instanceof LocalizedTerm, $item instanceof Term => array_values(array_filter([
                $item->taxonomyHandle() && $item->slug()
                    ? Tag::term((string) $item->taxonomyHandle(), (string) $item->slug())
                    : null,
                $item->taxonomyHandle() ? Tag::taxonomy((string) $item->taxonomyHandle()) : null,
            ])),

            $item instanceof Variables => [Tag::globalSet((string) $item->globalSet()->handle())],

            $item instanceof Form => [Tag::form((string) $item->handle())],

            // A nav is tagged where it renders rather than clearing the whole
            // cache, so a nav used on a handful of pages clears only those. A nav
            // in the shared layout still reaches every page — that is now an
            // emergent consequence of where it is used, not a hardcoded rule.
            $item instanceof Nav => [Tag::nav((string) $item->handle())],

            $item instanceof NavTree => [Tag::nav((string) $item->structure()->handle())],

            $item instanceof Collection => [Tag::collection((string) $item->handle())],

            // A tree save moves or reorders entries, which changes any listing
            // built from the collection.
            $item instanceof CollectionTree => [Tag::collection((string) $item->collection()->handle())],

            // Assets are not tracked on the read side yet, so there is nothing to
            // match. Statamic's own rule-based URLs still apply.
            $item instanceof Asset => [],

            default => [],
        };
    }
}
