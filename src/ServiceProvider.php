<?php

declare(strict_types=1);

namespace RoxDigital\CacheInvalidation;

use Statamic\Events\BlueprintSaved;
use Statamic\Providers\AddonServiceProvider;

class ServiceProvider extends AddonServiceProvider
{
    protected $config = false;

    protected $listen = [
        BlueprintSaved::class => [
            FlushStaticCacheOnFormBlueprintSaved::class,
        ],
    ];

    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__ . '/../config/cache_invalidation.php', 'cache_invalidation');

        $this->app
            ->when(ContentDependencyInvalidator::class)
            ->needs('$rules')
            ->giveConfig('statamic.static_caching.invalidation.rules');

        if ($this->app['config']->get('statamic.static_caching.invalidation.class') === null) {
            $this->app['config']->set(
                'statamic.static_caching.invalidation.class',
                ContentDependencyInvalidator::class,
            );
        }
    }

    public function bootAddon(): void
    {
        $this->publishes([
            __DIR__ . '/../config/cache_invalidation.php' => config_path('cache_invalidation.php'),
        ], 'cache-invalidation-config');
    }
}
