<?php

declare(strict_types=1);

namespace RoxDigital\CacheInvalidation\Tests;

use PHPUnit\Framework\Attributes\Test;
use Statamic\Facades\Collection;
use Statamic\Facades\Entry;
use Statamic\Facades\GlobalSet;
use Statamic\StaticCaching\Cacher;

/**
 * The one test that validates the whole premise: a real HTTP request, through
 * Statamic's frontend and its cache middleware, ends up with a graph row
 * describing what the template actually read.
 *
 * Everything else exercises the pieces. This exercises the seam between them —
 * middleware ordering, the cascade, and cachePage() being reached at all.
 */
final class RendersAndRecordsTest extends TestCase
{
    private string $listed;

    protected function setUp(): void
    {
        parent::setUp();

        Collection::make('articles')->save();

        $this->listed = tap(Entry::make()->collection('articles')->slug('listed')
            ->data(['title' => 'Listed article']))->save()->id();

        $set = tap(GlobalSet::make('footer'))->save();
        $set->makeLocalization('default')->data(['phone' => '0123'])->save();

        Collection::make('pages')->routes('/{slug}')->template('entry')->save();

        Entry::make()->collection('pages')->slug('about')->data(['title' => 'About us'])->save();
    }

    protected function resolveApplicationConfiguration($app): void
    {
        parent::resolveApplicationConfiguration($app);

        $app['config']->set('view.paths', [__DIR__ . '/__fixtures__/views']);
    }

    #[Test]
    public function rendering_a_page_records_everything_the_template_read(): void
    {
        $response = $this->get('/about');

        $response->assertOk();
        $response->assertSee('About us');
        $response->assertSee('Listed article');
        $response->assertSee('0123');

        $tags = $this->graph->tagsFor($this->urlFor('/about'));

        // The page's own entry, resolved by URI.
        $about = Entry::query()->where('collection', 'pages')->first();
        $this->assertContains("entry:{$about->id()}", $tags);

        // The listing it rendered: both the collection and the entry it showed.
        $this->assertContains('collection:articles', $tags);
        $this->assertContains("entry:{$this->listed}", $tags);

        // The global whose value it printed.
        $this->assertContains('global:footer', $tags);
    }

    #[Test]
    public function a_rendered_page_is_then_cleared_by_saving_what_it_read(): void
    {
        $this->get('/about')->assertOk();

        $cacher = app(Cacher::class);
        $this->assertNotEmpty($cacher->getUrls()->all(), 'the page should be in the static cache');

        // A save of the listed article, going through Statamic's own event chain.
        Entry::find($this->listed)->data(['title' => 'Renamed'])->save();

        $this->assertSame([], $cacher->getUrls()->values()->all());
    }

    #[Test]
    public function rendering_a_page_does_not_record_collections_it_never_touched(): void
    {
        // Statamic resolves every frontend request through findByUri(), which
        // queries `uri` with no collection scope. Treating that as a set query made
        // every rendered page depend on every collection on the site, quietly
        // degrading the addon to "clear everything on any entry save".
        Collection::make('unrelated')->save();
        Collection::make('another')->save();

        $this->get('/about')->assertOk();

        $tags = $this->graph->tagsFor($this->urlFor('/about'));

        $this->assertContains('collection:articles', $tags, 'the collection it listed');
        $this->assertNotContains('collection:unrelated', $tags);
        $this->assertNotContains('collection:another', $tags);
        $this->assertNotContains('collection:pages', $tags, 'its own collection was resolved by uri, not listed');
    }

    #[Test]
    public function saving_unrelated_content_leaves_a_rendered_page_cached(): void
    {
        Collection::make('unrelated')->save();

        $this->get('/about')->assertOk();

        $cacher = app(Cacher::class);
        $before = $cacher->getUrls()->values()->all();
        $this->assertNotEmpty($before);

        Entry::make()->collection('unrelated')->slug('thing')->data(['title' => 'Thing'])->save();

        // Nothing on the page reads the unrelated collection, and the page is
        // tracked, so the safety net does not apply either.
        $this->assertSame($before, $cacher->getUrls()->values()->all());
    }

    #[Test]
    public function the_debug_header_reports_the_recorded_tags(): void
    {
        config(['cache_invalidation.debug' => true]);

        $response = $this->get('/about');

        $header = $response->headers->get('X-Cache-Tags');

        $this->assertNotNull($header);
        $this->assertStringContainsString('collection:articles', $header);
        $this->assertStringContainsString('global:footer', $header);
    }

    private function urlFor(string $path): string
    {
        return app(Cacher::class)->getBaseUrl() . $path;
    }
}
