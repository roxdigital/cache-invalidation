<?php

declare(strict_types=1);

namespace RoxDigital\CacheInvalidation\Tests\Invalidation;

use PHPUnit\Framework\Attributes\Test;
use RoxDigital\CacheInvalidation\Tag;
use RoxDigital\CacheInvalidation\Tests\TestCase;
use Statamic\Facades\Collection;
use Statamic\Facades\Entry;
use Statamic\Facades\Form;
use Statamic\Facades\GlobalSet;
use Statamic\Facades\Nav;
use Statamic\Facades\Taxonomy;
use Statamic\Facades\Term;
use Statamic\StaticCaching\Cacher;

/**
 * End-to-end: content is saved for real, so the whole chain runs — Statamic's
 * Invalidate subscriber, the invalidator, and the cacher.
 *
 * Pages are seeded into the cache with a known tag set rather than rendered, so
 * each test states its dependencies in one line. What the recorders produce from a
 * real render is covered separately.
 */
final class InvalidatesByDependencyTest extends TestCase
{
    private Cacher $cacher;

    private string $domain;

    protected function setUp(): void
    {
        parent::setUp();

        $this->cacher = app(Cacher::class);
        $this->domain = $this->cacher->getBaseUrl();

        Collection::make('articles')->save();
        Collection::make('pages')->save();
    }

    #[Test]
    public function saving_an_entry_clears_pages_that_rendered_it_and_leaves_others(): void
    {
        $entry = $this->article('one');

        $this->cache('/uses-it', ["entry:{$entry->id()}"]);
        $this->cache('/unrelated', ['entry:something-else']);

        $entry->data(['title' => 'Changed'])->save();

        $this->assertCachedIs(['/unrelated']);
    }

    #[Test]
    public function saving_an_entry_clears_pages_that_listed_its_collection(): void
    {
        $entry = $this->article('one');

        $this->cache('/overview', ['collection:articles']);
        $this->cache('/unrelated', ['collection:pages']);

        $entry->data(['title' => 'Changed'])->save();

        $this->assertCachedIs(['/unrelated']);
    }

    #[Test]
    public function creating_an_entry_clears_listings_that_could_never_have_known_its_id(): void
    {
        // The reason list tags exist. A brand new entry's id appears in no recorded
        // tag set, so only the collection tag can reach the pages that list it.
        $this->cache('/overview', ['collection:articles']);
        $this->cache('/detail', ['entry:some-existing-id']);

        $this->article('brand-new');

        $this->assertCachedIs(['/detail']);
    }

    #[Test]
    public function a_page_embedding_one_reusable_block_is_unaffected_by_another(): void
    {
        Collection::make('reusable_blocks')->save();

        $a = tap(Entry::make()->collection('reusable_blocks')->slug('a')->data(['title' => 'A']))->save();
        $b = tap(Entry::make()->collection('reusable_blocks')->slug('b')->data(['title' => 'B']))->save();

        $this->cache('/uses-a-1', ["entry:{$a->id()}"]);
        $this->cache('/uses-a-2', ["entry:{$a->id()}"]);
        $this->cache('/uses-b', ["entry:{$b->id()}"]);

        $a->data(['title' => 'A changed'])->save();

        $this->assertCachedIs(['/uses-b']);
    }

    #[Test]
    public function a_transitive_dependency_clears_the_embedding_page(): void
    {
        // Page C embeds a reusable block that pulls in a global and another entry.
        // All three land in C's tag set, so any of them clears it.
        $this->makeGlobalSet('footer', ['phone' => '123']);

        $block = tap(Entry::make()->collection('pages')->slug('block')->data(['title' => 'Block']))->save();
        $inner = $this->article('inner');

        $this->cache('/page-c', ["entry:{$block->id()}", 'global:footer', "entry:{$inner->id()}"]);
        $this->cache('/page-d', ['entry:unrelated']);

        GlobalSet::find('footer')->in('default')->data(['phone' => '456'])->save();

        $this->assertCachedIs(['/page-d']);
    }

    #[Test]
    public function saving_a_global_clears_only_pages_that_read_it(): void
    {
        $this->makeGlobalSet('footer', ['phone' => '123']);
        $this->makeGlobalSet('seo', ['title' => 'Site']);

        $this->cache('/reads-footer', ['global:footer']);
        $this->cache('/reads-seo', ['global:seo']);

        GlobalSet::find('footer')->in('default')->data(['phone' => '456'])->save();

        $this->assertCachedIs(['/reads-seo']);
    }

    #[Test]
    public function saving_a_term_clears_pages_that_rendered_it_or_queried_its_taxonomy(): void
    {
        Taxonomy::make('topics')->save();
        $term = tap(Term::make('news')->taxonomy('topics')->data(['title' => 'News']))->save();

        $this->cache('/shows-term', ['term:topics::news']);
        $this->cache('/lists-taxonomy', ['taxonomy:topics']);
        $this->cache('/unrelated', ['taxonomy:other']);

        $term->in('default')->data(['title' => 'Nieuws'])->save();

        $this->assertCachedIs(['/unrelated']);
    }

    #[Test]
    public function saving_a_form_clears_only_pages_rendering_it(): void
    {
        Form::make('contact')->save();
        Form::make('newsletter')->save();

        $this->cache('/contact', ['form:contact']);
        $this->cache('/signup', ['form:newsletter']);

        Form::find('contact')->title('Contact us')->save();

        $this->assertCachedIs(['/signup']);
    }

    #[Test]
    public function saving_a_navigation_clears_every_cached_url(): void
    {
        Nav::make('main')->title('Main')->save();

        $this->cache('/a', ['entry:1']);
        $this->cache('/b', ['entry:2']);

        Nav::find('main')->title('Main nav')->save();

        // A reorder or relabel changes links rendered in shared layout, and no
        // per-page dependency can express that.
        $this->assertCachedIs([]);
    }

    #[Test]
    public function an_untracked_cached_url_is_cleared_by_any_save(): void
    {
        $entry = $this->article('one');

        $this->cacheWithoutRecording('/untracked');
        $this->cache('/tracked', ['entry:unrelated']);

        $entry->data(['title' => 'Changed'])->save();

        $this->assertCachedIs(['/tracked']);
    }

    #[Test]
    public function a_page_marked_as_depending_on_everything_is_always_cleared(): void
    {
        $entry = $this->article('one');

        $this->cache('/overflowed', [Tag::OVERFLOW]);
        $this->cache('/tracked', ['entry:unrelated']);

        $entry->data(['title' => 'Changed'])->save();

        $this->assertCachedIs(['/tracked']);
    }

    #[Test]
    public function saving_an_entry_clears_its_own_url(): void
    {
        Collection::make('articles')->routes('/articles/{slug}')->save();
        $entry = $this->article('one');

        $this->cache('/articles/one', []);
        $this->graph->record($this->domain . '/articles/one', ['entry:something-else']);
        $this->cache('/tracked', ['entry:unrelated']);

        $entry->data(['title' => 'Changed'])->save();

        // Statamic resolves the entry's own URL and descendants; the graph only has
        // to answer for pages that referenced it.
        $this->assertCachedIs(['/tracked']);
    }

    #[Test]
    public function a_flush_clears_the_graph_as_well(): void
    {
        $this->cache('/a', ['entry:1']);

        \Statamic\Facades\StaticCache::flush();

        $this->assertSame([], $this->graph->urls());
    }

    private function article(string $slug): \Statamic\Contracts\Entries\Entry
    {
        return tap(Entry::make()->collection('articles')->slug($slug)->data(['title' => ucfirst($slug)]))->save();
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function makeGlobalSet(string $handle, array $data): void
    {
        $set = tap(GlobalSet::make($handle))->save();
        $set->makeLocalization('default')->data($data)->save();
    }

    /**
     * @param  list<string>  $tags
     */
    private function cache(string $path, array $tags): void
    {
        $this->cacheWithoutRecording($path);

        if ($tags !== []) {
            $this->graph->record($this->domain . $path, $tags);
        }
    }

    private function cacheWithoutRecording(string $path): void
    {
        $this->cacher->cacheUrl(md5($path), $path, $this->domain);
    }

    /**
     * @param  list<string>  $expected
     */
    private function assertCachedIs(array $expected): void
    {
        $this->assertEqualsCanonicalizing(
            $expected,
            $this->cacher->getUrls($this->domain)->values()->all(),
        );
    }
}
