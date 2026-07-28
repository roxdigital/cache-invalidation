<?php

declare(strict_types=1);

namespace RoxDigital\CacheInvalidation;

use Illuminate\Support\Collection;
use Statamic\Facades\Site;
use Statamic\StaticCaching\Cacher;

class StaticCacheFlusher
{
    public function __construct(
        private readonly Cacher $cacher,
        private readonly PagebuilderDependencyScanner $pagebuilder,
    ) {}

    public function flush(): void
    {
        $this->invalidateSharedErrorUrls();
        $this->cacher->flush();
        $this->pagebuilder->clearIndex();
    }

    private function invalidateSharedErrorUrls(): void
    {
        $this->cacher->invalidateUrls($this->sharedErrorUrls()->all());
    }

    private function sharedErrorUrls(): Collection
    {
        return Site::all()
            ->flatMap(fn ($site): array => [
                "/__shared-errors/{$site->handle()}/404",
                rtrim($site->absoluteUrl(), '/') . "/__shared-errors/{$site->handle()}/404",
            ])
            ->unique()
            ->values();
    }
}
