<?php

declare(strict_types=1);

namespace RoxDigital\CacheInvalidation\Recording;

use Statamic\Contracts\Entries\Entry;
use Statamic\Stache\Repositories\EntryRepository;
use Statamic\Stache\Stache;

/**
 * Separates "which entry is this request for" from "what does this page display".
 *
 * findByUri() is not a plain lookup. For a structured collection it consults the
 * collection tree, and Tree::tree() runs CollectionStructure::validateTree(),
 * which plucks every entry in the collection to check the tree still matches. That
 * is a list query, so every request to a page in a structured collection recorded
 * that collection's list tag — and saving any single page then cleared every
 * cached page on the site.
 *
 * The page does not display that list; Statamic just reads it while resolving the
 * URL. Suppressing the whole call and then recording the entry it returned keeps
 * the dependency that is real and drops the bookkeeping that is not.
 */
final class TrackingEntryRepository extends EntryRepository
{
    public function __construct(
        Stache $stache,
        private readonly DependencyRecorder $recorder,
    ) {
        parent::__construct($stache);
    }

    public function findByUri(string $uri, ?string $site = null): ?Entry
    {
        $entry = $this->recorder->suppressed(fn (): ?Entry => parent::findByUri($uri, $site));

        if ($entry !== null) {
            $this->recorder->entries([$entry->id()]);
        }

        return $entry;
    }
}
