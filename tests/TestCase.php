<?php

declare(strict_types=1);

namespace RoxDigital\CacheInvalidation\Tests;

use Illuminate\Support\Facades\File;
use RoxDigital\CacheInvalidation\Graph\DependencyGraph;
use RoxDigital\CacheInvalidation\Recording\DependencyRecorder;
use RoxDigital\CacheInvalidation\ServiceProvider;
use Statamic\Facades\Blueprint;
use Statamic\Testing\AddonTestCase;
use Statamic\Testing\Concerns\PreventsSavingStacheItemsToDisk;

abstract class TestCase extends AddonTestCase
{
    use PreventsSavingStacheItemsToDisk;

    protected string $addonServiceProvider = ServiceProvider::class;

    protected DependencyRecorder $recorder;

    protected DependencyGraph $graph;

    protected function setUp(): void
    {
        parent::setUp();

        $this->recorder = app(DependencyRecorder::class);
        $this->graph = app(DependencyGraph::class);

        $this->graph->flush();
        $this->recorder->reset();
    }

    protected function tearDown(): void
    {
        File::delete($this->sqlitePath());

        parent::tearDown();
    }

    protected function resolveApplicationConfiguration($app): void
    {
        parent::resolveApplicationConfiguration($app);

        // A real strategy has to be active or the null cacher is used and nothing
        // is ever cached. Individual tests switch to 'full' where it matters.
        $app['config']->set('statamic.static_caching.strategy', 'half');
        $app['config']->set('cache_invalidation.sqlite_path', $this->sqlitePath());
    }

    protected function sqlitePath(): string
    {
        return __DIR__ . '/__fixtures__/dev-null/cache-invalidation.sqlite';
    }

    /**
     * The tags recorded while running the callback, in isolation.
     *
     * Every recording assertion goes through this rather than inspecting query
     * internals: the point is what a render ends up depending on, not how the
     * query was shaped.
     *
     * @return list<string>
     */
    protected function tagsRecordedDuring(callable $callback): array
    {
        $this->recorder->reset();

        $callback();

        return $this->recorder->tags();
    }

    /**
     * Registers a blueprint so entries can carry the given fields.
     *
     * @param  array<string, array<string, mixed>>  $fields
     */
    protected function blueprint(string $namespace, string $handle, array $fields = []): void
    {
        Blueprint::make($handle)
            ->setNamespace($namespace)
            ->setContents([
                'fields' => collect($fields)
                    ->map(fn (array $field, string $key): array => ['handle' => $key, 'field' => $field])
                    ->values()
                    ->all(),
            ])
            ->save();
    }
}
