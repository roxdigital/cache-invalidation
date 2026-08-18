<?php

declare(strict_types=1);

namespace RoxDigital\CacheInvalidation\Recording;

use Statamic\Facades\Taxonomy;
use Statamic\Stache\Query\TermQueryBuilder;
use Statamic\Stache\Stores\Store;

/**
 * The term equivalent of TrackingEntryQueryBuilder. Same two hooks, same reasons.
 */
final class TrackingTermQueryBuilder extends TermQueryBuilder
{
    use AppliesInheritedScopes;
    use DetectsItemLookups;

    public function __construct(
        Store $store,
        private readonly DependencyRecorder $recorder,
    ) {
        parent::__construct($store);
    }

    protected function getFilteredKeys()
    {
        if (! $this->isItemLookup()) {
            $this->recorder->taxonomies($this->taxonomies ?: Taxonomy::handles());
        }

        return parent::getFilteredKeys();
    }

    protected function getItems($keys)
    {
        $items = parent::getItems($keys);

        foreach ($items as $term) {
            if (($taxonomy = $term->taxonomyHandle()) && ($slug = $term->slug())) {
                $this->recorder->term((string) $taxonomy, (string) $slug);
            }
        }

        return $items;
    }
}
