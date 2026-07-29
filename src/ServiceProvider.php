<?php

declare(strict_types=1);

namespace RoxDigital\CacheInvalidation;

use Statamic\Events\BlueprintSaved;
use Statamic\Events\CollectionTreeSaved;
use Statamic\Providers\AddonServiceProvider;

class ServiceProvider extends AddonServiceProvider
{
    protected $config = false;

    protected $listen = [
        BlueprintSaved::class => [
            FlushStaticCacheOnFormBlueprintSaved::class,
        ],
        CollectionTreeSaved::class => [
            HandleCollectionTreeSaved::class,
        ],
    ];

    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__ . '/../config/cache_invalidation.php', 'cache_invalidation');

        if ($this->app['config']->get('statamic.static_caching.invalidation.class') === null) {
            $this->app['config']->set(
                'statamic.static_caching.invalidation.class',
                ContentDependencyInvalidator::class,
            );
        }

        /*
         * Contextual bindings are keyed on the exact concrete, so binding only
         * this class leaves a host app that points the config at a subclass with
         * an unresolvable $rules parameter. Bind the configured class too.
         */
        $concretes = array_unique(array_filter([
            ContentDependencyInvalidator::class,
            $this->app['config']->get('statamic.static_caching.invalidation.class'),
        ]));

        foreach ($concretes as $concrete) {
            $this->app
                ->when($concrete)
                ->needs('$rules')
                ->giveConfig('statamic.static_caching.invalidation.rules');
        }
    }

    public function bootAddon(): void
    {
        $this->publishes([
            __DIR__ . '/../config/cache_invalidation.php' => config_path('cache_invalidation.php'),
        ], 'cache-invalidation-config');
    }
}
