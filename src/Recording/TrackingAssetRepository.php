<?php

declare(strict_types=1);

namespace RoxDigital\CacheInvalidation\Recording;

use Statamic\Assets\AssetRepository;

/**
 * Records assets resolved by id or URL rather than by query.
 *
 * Both skip the query builder entirely — they build the asset straight off its
 * container — and both are reached from rendering: Bard resolves inline images
 * through find(), and the glide tag resolves a string `src` the same way. find()
 * and findOrFail() dispatch through these two, so hooking them covers all four.
 *
 * all(), whereContainer() and whereFolder() are left alone: they go through the
 * container's own query, which TrackingAssetQueryBuilder already records.
 */
final class TrackingAssetRepository extends AssetRepository
{
    public function __construct(
        private readonly DependencyRecorder $recorder,
    ) {}

    public function findById(string $id)
    {
        return $this->record(parent::findById($id));
    }

    public function findByUrl(string $url)
    {
        return $this->record(parent::findByUrl($url));
    }

    private function record(mixed $asset): mixed
    {
        if ($asset !== null) {
            $this->recorder->assets([$asset->id()]);
        }

        return $asset;
    }
}
