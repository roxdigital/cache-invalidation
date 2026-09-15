<?php

declare(strict_types=1);

namespace RoxDigital\CacheInvalidation\Tests;

use PHPUnit\Framework\Attributes\Test;
use RoxDigital\CacheInvalidation\Graph\DependencyGraph;
use RoxDigital\CacheInvalidation\Graph\SqliteGraph;

/**
 * `php artisan config:cache` makes ServiceProvider::mergeConfigFrom a no-op, so a
 * site that never published config/cache_invalidation.php has no
 * cache_invalidation namespace at all at runtime.
 *
 * That is the documented install — "nothing to publish" — combined with a standard
 * deploy step, so the defaults cannot live only in the config file.
 */
final class CachedConfigTest extends TestCase
{
    #[Test]
    public function the_graph_still_works_with_no_cache_invalidation_config_at_all(): void
    {
        config()->set('cache_invalidation', []);
        app()->forgetInstance(DependencyGraph::class);

        $graph = app(DependencyGraph::class);

        $this->assertInstanceOf(SqliteGraph::class, $graph);

        $graph->record('https://site.test/a', ['entry:1']);

        $this->assertSame(['entry:1'], $graph->tagsFor('https://site.test/a'));
    }

    #[Test]
    public function the_sqlite_connection_is_registered_with_a_usable_path(): void
    {
        config()->set('cache_invalidation', []);

        $path = config('database.connections.'.SqliteGraph::CONNECTION.'.database');

        $this->assertIsString($path);
        $this->assertNotSame('', $path);
    }

    #[Test]
    public function the_commands_still_run_with_no_config(): void
    {
        config()->set('cache_invalidation', []);
        app()->forgetInstance(DependencyGraph::class);

        $this->artisan('cache-invalidation:stats')->assertSuccessful();
        $this->artisan('cache-invalidation:doctor')->assertSuccessful();
    }
}
