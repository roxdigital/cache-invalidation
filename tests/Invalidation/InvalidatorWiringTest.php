<?php

declare(strict_types=1);

namespace RoxDigital\CacheInvalidation\Tests\Invalidation;

use Illuminate\Queue\Events\JobProcessing;
use Mockery;
use PHPUnit\Framework\Attributes\Test;
use RoxDigital\CacheInvalidation\CachedUrls;
use RoxDigital\CacheInvalidation\Graph\DatabaseGraph;
use RoxDigital\CacheInvalidation\Graph\DependencyGraph;
use RoxDigital\CacheInvalidation\Graph\NullGraph;
use RoxDigital\CacheInvalidation\Graph\SqliteGraph;
use RoxDigital\CacheInvalidation\Invalidation\GraphInvalidator;
use RoxDigital\CacheInvalidation\Invalidation\TagResolver;
use RoxDigital\CacheInvalidation\Recording\DependencyRecorder;
use RoxDigital\CacheInvalidation\Recording\TrackingEntryRepository;
use RoxDigital\CacheInvalidation\Recording\TrackingFormRepository;
use RoxDigital\CacheInvalidation\Recording\TrackingNavigationRepository;
use RoxDigital\CacheInvalidation\Recording\TrackingTermRepository;
use RoxDigital\CacheInvalidation\Tests\Doubles\HostInvalidator;
use RoxDigital\CacheInvalidation\Tests\TestCase;
use Statamic\Facades\Collection;
use Statamic\Facades\Entry;
use Statamic\StaticCaching\Cacher;
use Statamic\StaticCaching\Invalidator;

final class InvalidatorWiringTest extends TestCase
{
    /** Set before the application is created; see resolveApplicationConfiguration. */
    public static ?string $pin = null;

    protected function tearDown(): void
    {
        static::$pin = null;

        parent::tearDown();
    }

    protected function resolveApplicationConfiguration($app): void
    {
        parent::resolveApplicationConfiguration($app);

        if (static::$pin !== null) {
            $app['config']->set('statamic.static_caching.invalidation.class', static::$pin);
        }
    }

    #[Test]
    public function it_claims_the_invalidator_when_the_config_leaves_it_unset(): void
    {
        $this->assertInstanceOf(GraphInvalidator::class, app(Invalidator::class));
    }

    #[Test]
    public function it_upgrades_a_pin_at_one_of_its_own_removed_classes(): void
    {
        // Sites pin this by name. A 1.x site pinning ContentDependencyInvalidator
        // would otherwise fatal on a class that no longer exists — or, before the
        // class was removed, silently keep the old behaviour after an upgrade.
        static::$pin = 'RoxDigital\CacheInvalidation\ContentDependencyInvalidator';
        $this->refreshApplication();

        $this->assertSame(
            GraphInvalidator::class,
            config('statamic.static_caching.invalidation.class'),
        );
    }

    #[Test]
    public function it_respects_an_invalidator_belonging_to_the_host_app(): void
    {
        static::$pin = HostInvalidator::class;
        $this->refreshApplication();

        $this->assertSame(HostInvalidator::class, config('statamic.static_caching.invalidation.class'));
        $this->assertInstanceOf(HostInvalidator::class, app(Invalidator::class));
    }

    #[Test]
    public function it_resolves_the_invalidator_when_the_host_config_has_no_rules_key(): void
    {
        // Contextual $rules bindings resolve from config, which returns null when
        // the key is missing. Without a default that fatals on a TypeError.
        config(['statamic.static_caching.invalidation.rules' => null]);
        app()->forgetInstance(Invalidator::class);

        $this->assertInstanceOf(GraphInvalidator::class, app(Invalidator::class));
    }

    #[Test]
    public function it_refreshes_rather_than_purges_when_background_recache_is_on(): void
    {
        // DefaultInvalidator::refresh() flips a protected flag and delegates to
        // invalidate(). v1 overrode invalidate() without checking it and always
        // hard-purged, which silently broke background_recache.
        config(['statamic.static_caching.background_recache' => true]);

        Collection::make('articles')->save();
        $entry = tap(Entry::make()->collection('articles')->slug('one')->data(['title' => 'One']))->save();

        $cacher = Mockery::mock(Cacher::class);
        $cacher->shouldReceive('refreshUrls')->once();
        $cacher->shouldNotReceive('invalidateUrls');

        $invalidator = new GraphInvalidator(
            $cacher,
            [],
            $this->graph,
            app(TagResolver::class),
            new CachedUrls($cacher),
        );

        $this->graph->record('https://site.test/a', ["entry:{$entry->id()}"]);

        $invalidator->refresh($entry);
    }

    #[Test]
    public function it_purges_when_background_recache_is_off(): void
    {
        config(['statamic.static_caching.background_recache' => false]);

        Collection::make('articles')->save();
        $entry = tap(Entry::make()->collection('articles')->slug('one')->data(['title' => 'One']))->save();

        $cacher = Mockery::mock(Cacher::class);
        $cacher->shouldReceive('invalidateUrls')->once();
        $cacher->shouldNotReceive('refreshUrls');

        $invalidator = new GraphInvalidator(
            $cacher,
            [],
            $this->graph,
            app(TagResolver::class),
            new CachedUrls($cacher),
        );

        $this->graph->record('https://site.test/a', ["entry:{$entry->id()}"]);

        $invalidator->refresh($entry);
    }

    #[Test]
    public function the_facades_resolve_the_tracking_repositories(): void
    {
        // Facades cache the instance they resolved. Rebinding in boot is therefore
        // not enough on its own: anything touching a facade earlier in the boot cycle
        // keeps the original repository and its reads go unrecorded.
        //
        // This asserts the invariant, not the failure — a stale facade depends on
        // boot ordering that the test harness does not reproduce. It was verified
        // against two live sites, one of which recorded nothing through
        // Entry::findByUri() until the provider started clearing these.
        $this->assertInstanceOf(TrackingEntryRepository::class, \Statamic\Facades\Entry::getFacadeRoot());
        $this->assertInstanceOf(TrackingTermRepository::class, \Statamic\Facades\Term::getFacadeRoot());
        $this->assertInstanceOf(TrackingNavigationRepository::class, \Statamic\Facades\Nav::getFacadeRoot());
        $this->assertInstanceOf(TrackingFormRepository::class, \Statamic\Facades\Form::getFacadeRoot());
    }

    #[Test]
    public function resolving_a_url_through_the_facade_records_only_the_entry(): void
    {
        $collection = Collection::make('pages')->routes('/{slug}')->structureContents(['root' => false]);
        $collection->save();

        $entry = tap(Entry::make()->collection('pages')->slug('about')->data(['title' => 'About']))->save();
        $collection->structure()->in('default')->tree([['entry' => $entry->id()]])->save();

        // Resolved before recording; uri() consults the tree itself.
        $uri = (string) $entry->fresh()->uri();
        $this->assertNotSame('', $uri, 'the fixture entry needs a resolvable uri');

        \Statamic\Facades\Blink::flush();

        $tags = $this->tagsRecordedDuring(fn () => Entry::findByUri($uri, 'default'));

        $this->assertSame(["entry:{$entry->id()}"], $tags);
    }

    #[Test]
    public function it_resolves_the_configured_graph_driver(): void
    {
        $this->assertInstanceOf(SqliteGraph::class, app(DependencyGraph::class));

        config(['cache_invalidation.driver' => 'null']);
        app()->forgetInstance(DependencyGraph::class);
        $this->assertInstanceOf(NullGraph::class, app(DependencyGraph::class));

        config(['cache_invalidation.driver' => 'database']);
        app()->forgetInstance(DependencyGraph::class);
        $this->assertInstanceOf(DatabaseGraph::class, app(DependencyGraph::class));
    }

    #[Test]
    public function the_null_driver_treats_every_cached_url_as_untracked(): void
    {
        // Which is what makes it behave as "clear everything on every save".
        $graph = new NullGraph;

        $this->assertSame(['a', 'b'], $graph->untracked(['a', 'b']));
    }

    #[Test]
    public function the_recorder_is_reset_between_queued_jobs(): void
    {
        // A worker keeps the container alive across jobs, so without this the tag
        // set would grow until it overflowed and every page would look like it
        // depended on everything.
        $this->recorder->add('entry:stale');

        $job = Mockery::mock(\Illuminate\Contracts\Queue\Job::class);
        $job->shouldReceive('payload')->andReturn([]);

        event(new JobProcessing('sync', $job));

        $this->assertSame([], app(DependencyRecorder::class)->tags());
    }
}
