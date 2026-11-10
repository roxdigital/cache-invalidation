<?php

declare(strict_types=1);

namespace RoxDigital\CacheInvalidation\Recording;

use Statamic\Assets\QueryBuilder;
use Statamic\Contracts\Assets\AssetContainer;
use Statamic\Stache\Stores\Store;

/**
 * The asset equivalent of TrackingEntryQueryBuilder. Same two hooks, same reasons.
 *
 * This is the path the assets fieldtype takes: Assets::augment() runs
 * `$container->queryAssets()->whereIn('path', $values)`, so an image field on a
 * page lands here as an item lookup and records only the assets it shows.
 *
 * Only getItems() records item tags, and only for assets that are actually
 * returned. Building an asset is cheap and does not read its meta, so nothing here
 * adds work to the render beyond the tag itself.
 */
final class TrackingAssetQueryBuilder extends QueryBuilder
{
    use AppliesInheritedScopes;
    use DetectsItemLookups;

    public function __construct(
        Store $store,
        private readonly DependencyRecorder $recorder,
    ) {
        parent::__construct($store);
    }

    public function getFilteredKeys()
    {
        // Resolved before the parent runs, which throws when no container was
        // given — there is then nothing to record either.
        if (! $this->isItemLookup() && ($handle = $this->containerHandle()) !== null) {
            $this->recorder->assetContainer($handle);
        }

        return parent::getFilteredKeys();
    }

    protected function getItems($keys)
    {
        $items = parent::getItems($keys);

        $this->recorder->assets($items->map->id());

        return $items;
    }

    /**
     * An asset is identified by its path within the container, which is the
     * column the fieldtype queries. where('container', ...) never lands in
     * $wheres — the parent intercepts it — so it cannot be mistaken for one.
     *
     * @return list<string>
     */
    protected function identifyingColumns(): array
    {
        return ['id', 'path'];
    }

    private function containerHandle(): ?string
    {
        $container = $this->container;

        if ($container instanceof AssetContainer) {
            return (string) $container->handle();
        }

        return is_string($container) && $container !== '' ? $container : null;
    }
}
