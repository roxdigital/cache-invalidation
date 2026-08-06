<?php

declare(strict_types=1);

namespace RoxDigital\CacheInvalidation;

use Illuminate\Database\DatabaseManager;
use Illuminate\Queue\Events\JobProcessing;
use Illuminate\Support\Facades\Blade;
use Illuminate\Support\Facades\Event;
use RoxDigital\CacheInvalidation\Blade\CacheTagsDirective;
use RoxDigital\CacheInvalidation\Cachers\TrackingApplicationCacher;
use RoxDigital\CacheInvalidation\Cachers\TrackingFileCacher;
use RoxDigital\CacheInvalidation\Console\AffectedCommand;
use RoxDigital\CacheInvalidation\Console\DoctorCommand;
use RoxDigital\CacheInvalidation\Console\StatsCommand;
use RoxDigital\CacheInvalidation\Console\WhyCommand;
use RoxDigital\CacheInvalidation\Graph\ClearGraphWhenCacheCleared;
use RoxDigital\CacheInvalidation\Graph\DatabaseGraph;
use RoxDigital\CacheInvalidation\Graph\DependencyGraph;
use RoxDigital\CacheInvalidation\Graph\NullGraph;
use RoxDigital\CacheInvalidation\Graph\SqliteGraph;
use RoxDigital\CacheInvalidation\Http\AddCacheTagsHeader;
use RoxDigital\CacheInvalidation\Invalidation\GraphInvalidator;
use RoxDigital\CacheInvalidation\Recording\DependencyRecorder;
use RoxDigital\CacheInvalidation\Recording\TrackingEntryQueryBuilder;
use RoxDigital\CacheInvalidation\Recording\TrackingFormRepository;
use RoxDigital\CacheInvalidation\Recording\TrackingTermRepository;
use RoxDigital\CacheInvalidation\Recording\TrackingVariables;
use Statamic\Contracts\Entries\QueryBuilder as EntryQueryBuilderContract;
use Statamic\Contracts\Forms\FormRepository as FormRepositoryContract;
use Statamic\Contracts\Globals\Variables as VariablesContract;
use Statamic\Contracts\Taxonomies\TermRepository as TermRepositoryContract;
use Statamic\Events\StaticCacheCleared;
use Statamic\Facades\StaticCache;
use Statamic\Providers\AddonServiceProvider;
use Statamic\Stache\Query\EntryQueryBuilder;
use Statamic\Stache\Stache;
use Statamic\Stache\Stores\Store;
use Statamic\Statamic;
use Statamic\StaticCaching\Cachers\Writer;
use Statamic\StaticCaching\StaticCacheManager;

class ServiceProvider extends AddonServiceProvider
{
    protected $config = false;

    protected $commands = [
        AffectedCommand::class,
        DoctorCommand::class,
        StatsCommand::class,
        WhyCommand::class,
    ];

    protected $middlewareGroups = [
        'statamic.web' => [
            AddCacheTagsHeader::class,
        ],
    ];

    protected $listen = [
        StaticCacheCleared::class => [
            ClearGraphWhenCacheCleared::class,
        ],
    ];

    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__ . '/../config/cache_invalidation.php', 'cache_invalidation');

        $this->registerSqliteConnection();
        $this->registerGraph();
        $this->registerRecorder();
        $this->registerTrackingCachers();
        $this->registerInvalidator();
    }

    public function bootAddon(): void
    {
        $this->publishes([
            __DIR__ . '/../config/cache_invalidation.php' => config_path('cache_invalidation.php'),
        ], 'cache-invalidation-config');

        // The default sqlite driver creates its own schema, so only the opt-in
        // database driver has anything for `php artisan migrate` to find.
        if ($this->graphDriver() === 'database') {
            $this->loadMigrationsFrom(__DIR__ . '/../database/migrations');
        }

        $this->registerReadRecorders();

        Blade::directive('cachetags', CacheTagsDirective::compile(...));
    }

    /**
     * A dedicated connection owned by the addon, so the graph works on a site
     * with no DB_CONNECTION configured — which is the common Statamic case.
     */
    private function registerSqliteConnection(): void
    {
        $this->app['config']->set('database.connections.' . SqliteGraph::CONNECTION, [
            'driver' => 'sqlite',
            'database' => $this->app['config']->get('cache_invalidation.sqlite_path'),
            'prefix' => '',
            'foreign_key_constraints' => false,
            'journal_mode' => 'wal',
            'busy_timeout' => 5000,
        ]);
    }

    private function registerGraph(): void
    {
        $this->app->singleton(DependencyGraph::class, fn ($app): DependencyGraph => match ($this->graphDriver()) {
            'database' => new DatabaseGraph(
                $app->make(DatabaseManager::class),
                $app['config']->get('cache_invalidation.database_connection'),
            ),
            'null' => new NullGraph,
            default => new SqliteGraph(
                $app->make(DatabaseManager::class),
                (string) $app['config']->get('cache_invalidation.sqlite_path'),
            ),
        });
    }

    private function registerRecorder(): void
    {
        $this->app->singleton(DependencyRecorder::class);

        // In PHP-FPM the singleton's lifetime is the request. A queue worker
        // keeps the container alive across jobs, so the tag set has to be cleared
        // between them or it would grow until it overflowed. (Octane needs the
        // same treatment via its RequestReceived event.)
        Event::listen(JobProcessing::class, function (): void {
            $this->app->make(DependencyRecorder::class)->reset();
        });
    }

    /**
     * Subclasses of the concrete cachers rather than a decorator on the Cacher
     * binding: Statamic's cache middleware branches on `instanceof
     * ApplicationCacher`, `FileCacher` and `NullCacher`, and a wrapper would
     * silently change which responses are cached.
     *
     * Registered through afterResolving so the custom creators are in place
     * before anything calls driver() on the manager.
     */
    private function registerTrackingCachers(): void
    {
        $this->app->afterResolving(StaticCacheManager::class, function (StaticCacheManager $manager): void {
            // Statamic's Manager hands custom creators the same fully merged
            // config its own createXDriver() methods receive — exclusions, query
            // string handling and locale included — so nothing has to be
            // reconstructed here.
            $manager->extend('application', fn ($app, array $config): TrackingApplicationCacher => new TrackingApplicationCacher(
                StaticCache::cacheStore(),
                $config,
            ));

            $manager->extend('file', fn ($app, array $config): TrackingFileCacher => new TrackingFileCacher(
                new Writer($config['permissions'] ?? []),
                StaticCache::cacheStore(),
                $config,
            ));
        });
    }

    /**
     * Claims the invalidator unless the host app points at a class of its own.
     *
     * The "of its own" test matters on upgrade: sites pin this by name, and a v1
     * site pinning ContentDependencyInvalidator must follow the addon forward
     * rather than fataling on a class that no longer exists. A genuinely foreign
     * subclass is still respected.
     */
    private function registerInvalidator(): void
    {
        $configured = $this->app['config']->get('statamic.static_caching.invalidation.class');

        if ($configured === null || $this->isOwnInvalidator((string) $configured)) {
            $this->app['config']->set('statamic.static_caching.invalidation.class', GraphInvalidator::class);
        }

        /*
         * Contextual bindings are keyed on the exact concrete, so binding only
         * our own class leaves a host app that points the config at a subclass
         * with an unresolvable $rules parameter. Bind the configured class too.
         */
        $concretes = array_unique(array_filter([
            GraphInvalidator::class,
            $this->app['config']->get('statamic.static_caching.invalidation.class'),
        ]));

        foreach ($concretes as $concrete) {
            $this->app
                ->when($concrete)
                ->needs('$rules')
                ->giveConfig('statamic.static_caching.invalidation.rules');
        }
    }

    /**
     * Deliberately in boot rather than register: Statamic's Stache provider binds
     * EntryQueryBuilder unconditionally in its own register(), so a binding made
     * during register() would be clobbered if our provider happened to run first.
     * Boot runs after every register(), and nothing resolves a query builder,
     * global set or form before a request or command is handled.
     */
    private function registerReadRecorders(): void
    {
        $recorder = fn (): DependencyRecorder => $this->app->make(DependencyRecorder::class);
        $entries = fn (): Store => $this->app->make(Stache::class)->store('entries');

        $builder = fn (): TrackingEntryQueryBuilder => new TrackingEntryQueryBuilder($entries(), $recorder());

        // EntryRepository::query() resolves the contract; the concrete is bound
        // too, in case anything resolves it directly.
        $this->app->bind(EntryQueryBuilderContract::class, $builder);
        $this->app->bind(EntryQueryBuilder::class, $builder);

        // TermRepository::query() constructs its builder directly instead of
        // resolving it, so the repository itself has to be replaced.
        Statamic::repository(TermRepositoryContract::class, TrackingTermRepository::class);

        // The global variables store builds its items with app(Variables::class).
        $this->app->bind(VariablesContract::class, TrackingVariables::class);

        Statamic::repository(FormRepositoryContract::class, TrackingFormRepository::class);
    }

    private function isOwnInvalidator(string $class): bool
    {
        return str_starts_with($class, __NAMESPACE__ . '\\');
    }

    private function graphDriver(): string
    {
        return (string) $this->app['config']->get('cache_invalidation.driver', 'sqlite');
    }
}
