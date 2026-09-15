<?php

declare(strict_types=1);

namespace RoxDigital\CacheInvalidation\Tests\Cachers;

use Illuminate\Http\Request;
use Illuminate\Http\Response;
use PHPUnit\Framework\Attributes\Test;
use RoxDigital\CacheInvalidation\Cachers\TrackingApplicationCacher;
use RoxDigital\CacheInvalidation\Cachers\TrackingFileCacher;
use RoxDigital\CacheInvalidation\Tests\TestCase;
use Statamic\StaticCaching\Cacher;
use Statamic\StaticCaching\Cachers\AbstractCacher;
use Statamic\StaticCaching\Cachers\ApplicationCacher;
use Statamic\StaticCaching\Cachers\FileCacher;

final class RecordsDependenciesTest extends TestCase
{
    #[Test]
    public function the_half_measure_cacher_is_replaced_and_still_passes_statamics_type_checks(): void
    {
        $cacher = app(Cacher::class);

        $this->assertInstanceOf(TrackingApplicationCacher::class, $cacher);

        // Statamic's cache middleware branches on these to decide which status
        // codes are cacheable and whether exclusions apply. A decorator would
        // fail them silently, which is why the concrete cachers are subclassed.
        $this->assertInstanceOf(ApplicationCacher::class, $cacher);
        $this->assertInstanceOf(AbstractCacher::class, $cacher);
    }

    #[Test]
    public function the_full_measure_cacher_is_replaced_and_still_passes_statamics_type_checks(): void
    {
        $cacher = $this->fullMeasureCacher();

        $this->assertInstanceOf(TrackingFileCacher::class, $cacher);
        $this->assertInstanceOf(FileCacher::class, $cacher);
        $this->assertInstanceOf(AbstractCacher::class, $cacher);
    }

    #[Test]
    public function caching_a_page_records_the_recorded_tags_against_its_url(): void
    {
        $cacher = app(Cacher::class);
        $this->recorder->add('entry:1', 'global:footer');

        $request = Request::create('http://localhost/about');
        $cacher->cachePage($request, new Response('<html></html>'));

        $this->assertEqualsCanonicalizing(
            ['entry:1', 'global:footer'],
            $this->graph->tagsFor($cacher->getUrl($request)),
        );
    }

    #[Test]
    public function full_measure_records_against_the_same_url_as_half_measure(): void
    {
        // Both strategies must behave identically; this is the assertion that
        // stops one driver silently keying rows differently from the other.
        $half = app(Cacher::class);
        $full = $this->fullMeasureCacher();

        $request = Request::create('http://localhost/about');

        $this->assertSame($half->getUrl($request), $full->getUrl($request));

        $this->recorder->add('entry:1');
        $full->cachePage($request, new Response('<html></html>'));

        $this->assertSame(['entry:1'], $this->graph->tagsFor($full->getUrl($request)));
    }

    #[Test]
    public function an_excluded_url_is_not_recorded(): void
    {
        config(['statamic.static_caching.exclude.urls' => ['/private']]);

        $cacher = app(Cacher::class);
        $this->recorder->add('entry:1');

        $request = Request::create('http://localhost/private');
        $cacher->cachePage($request, new Response('<html></html>'));

        // An excluded URL is never cached, so it must not gain a row either —
        // otherwise the graph would claim coverage it does not have.
        $this->assertSame([], $this->graph->tagsFor($cacher->getUrl($request)));
    }

    #[Test]
    public function a_page_that_recorded_nothing_is_left_untracked(): void
    {
        $cacher = app(Cacher::class);

        $request = Request::create('http://localhost/empty');
        $cacher->cachePage($request, new Response('<html></html>'));

        $url = $cacher->getUrl($request);

        $this->assertSame([], $this->graph->tagsFor($url));
        $this->assertSame([$url], $this->graph->untracked([$url]));
    }

    #[Test]
    public function recording_failure_does_not_break_the_page_render(): void
    {
        // A visitor's page must not 500 because the graph could not be written.
        // The URL is then untracked, and the safety net clears it on the next save.
        config(['cache_invalidation.sqlite_path' => '/nonexistent-directory/x/y.sqlite']);
        app()->forgetInstance(\RoxDigital\CacheInvalidation\Graph\DependencyGraph::class);

        $cacher = app(Cacher::class);
        $this->recorder->add('entry:1');

        $cacher->cachePage(Request::create('http://localhost/about'), new Response('<html></html>'));

        $this->assertTrue(true, 'cachePage completed despite an unwritable graph');
    }

    private function fullMeasureCacher(): Cacher
    {
        config([
            'statamic.static_caching.strategy' => 'full',
            'statamic.static_caching.strategies.full.path' => __DIR__ . '/../__fixtures__/dev-null/static',
        ]);

        app()->forgetInstance(\Statamic\StaticCaching\StaticCacheManager::class);

        return app(Cacher::class);
    }
}
