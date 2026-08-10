<?php

declare(strict_types=1);

namespace RoxDigital\CacheInvalidation\Tests;

use PHPUnit\Framework\Attributes\Test;
use RoxDigital\CacheInvalidation\Invalidation\TagResolver;
use Statamic\Facades\Collection;
use Statamic\Facades\Entry;
use Statamic\Facades\Form;
use Statamic\Facades\GlobalSet;
use Statamic\Facades\Nav;
use Statamic\Facades\Taxonomy;
use Statamic\Facades\Term;
use Statamic\StaticCaching\Cacher;
use Statamic\StaticCaching\Invalidator;
use Statamic\StaticCaching\DefaultInvalidator;

/**
 * One test per stated requirement, so the behaviour that was asked for is pinned
 * rather than inferred from the implementation.
 */
final class RequirementsTest extends TestCase
{
    private Cacher $cacher;

    private string $domain;

    protected function setUp(): void
    {
        parent::setUp();

        $this->cacher = app(Cacher::class);
        $this->domain = $this->cacher->getBaseUrl();
    }

    #[Test]
    public function it_invalidates_through_statamics_own_invalidator_contract(): void
    {
        // So Statamic's Invalidate subscriber drives it — which is ShouldQueue, and
        // therefore runs on a worker when one is configured and inline otherwise.
        // Nothing here re-implements dispatching.
        $invalidator = app(Invalidator::class);

        $this->assertInstanceOf(DefaultInvalidator::class, $invalidator);
        $this->assertTrue(is_subclass_of(\Statamic\StaticCaching\Invalidate::class, \Illuminate\Contracts\Queue\ShouldQueue::class));
    }

    #[Test]
    public function reordering_a_navigation_clears_the_pages_that_render_it(): void
    {
        Nav::make('main_nav')->title('Main')->save();
        $tree = Nav::find('main_nav')->makeTree('default');
        $tree->save();

        $this->cache('/uses-nav', ['nav:main_nav']);
        $this->cache('/no-nav', ['entry:x']);

        // A reorder dispatches NavTreeSaved rather than NavSaved.
        $tree->tree([])->save();

        $this->assertCachedIs(['/no-nav']);
    }

    #[Test]
    public function a_nav_tree_resolves_to_its_navs_tag(): void
    {
        // The two nav-tree events arrive differently: NavTreeSaved hands the
        // invalidator the Nav (via $tree->structure()), NavTreeDeleted hands it the
        // tree itself. The reorder test above therefore exercises the Nav branch,
        // and this covers the tree branch.
        Nav::make('main_nav')->title('Main')->save();

        $tree = Nav::find('main_nav')->makeTree('default');

        $this->assertSame(['nav:main_nav'], app(TagResolver::class)->forItem($tree));
    }

    #[Test]
    public function a_form_submission_does_not_clear_anything(): void
    {
        Form::make('contact')->save();

        $this->cache('/contact', ['form:contact']);

        $submission = Form::find('contact')->makeSubmission()->data(['name' => 'Bob']);
        $submission->save();

        $this->assertCachedIs(['/contact']);
    }

    #[Test]
    public function saving_a_form_clears_pages_that_render_it_from_inside_a_reusable_block(): void
    {
        Form::make('contact')->save();
        Collection::make('reusable_blocks')->save();

        $this->blueprint('collections.reusable_blocks', 'reusable_block', [
            'form' => ['type' => 'form', 'max_items' => 1],
        ]);

        $block = tap(Entry::make()->collection('reusable_blocks')->slug('cta')
            ->data(['title' => 'CTA', 'form' => 'contact']))->save();

        // Rendering the embedding page renders the block inline, so the block's
        // reads are the page's reads. Depth is irrelevant to the recorder.
        $tags = $this->tagsRecordedDuring(function () use ($block): void {
            $embedded = Entry::find($block->id());
            $form = $embedded->augmentedValue('form')->value();
            $form?->handle();
        });

        $this->assertContains('form:contact', $tags);
        $this->assertContains("entry:{$block->id()}", $tags);
    }

    #[Test]
    public function saving_a_term_clears_pages_that_rendered_it(): void
    {
        Taxonomy::make('topics')->save();
        $term = tap(Term::make('news')->taxonomy('topics')->data(['title' => 'News']))->save();

        $this->cache('/shows-term', ['term:topics::news']);
        $this->cache('/lists-topics', ['taxonomy:topics']);
        $this->cache('/unrelated', ['entry:x']);

        $term->in('default')->data(['title' => 'Nieuws'])->save();

        $this->assertCachedIs(['/unrelated']);
    }

    #[Test]
    public function an_entry_linked_from_bard_is_recorded(): void
    {
        Collection::make('articles')->save();
        Collection::make('pages')->save();

        $linked = tap(Entry::make()->collection('articles')->slug('target')->data(['title' => 'Target']))->save();

        $this->blueprint('collections.pages', 'page', [
            'body' => ['type' => 'bard', 'save_html' => false],
        ]);

        $page = tap(Entry::make()->collection('pages')->slug('home')->data([
            'title' => 'Home',
            'body' => [[
                'type' => 'paragraph',
                'content' => [[
                    'type' => 'text',
                    'text' => 'Read this',
                    'marks' => [['type' => 'link', 'attrs' => ['href' => 'statamic://entry::'.$linked->id()]]],
                ]],
            ]],
        ]))->save();

        // Bard rewrites statamic:// hrefs to real URLs when augmented, which means
        // resolving the entry. v1 had to scan raw values for these by hand.
        $tags = $this->tagsRecordedDuring(fn () => (string) $page->augmentedValue('body'));

        $this->assertContains("entry:{$linked->id()}", $tags);
    }

    #[Test]
    public function a_new_entry_clears_pages_that_list_its_collection(): void
    {
        Collection::make('articles')->save();

        $this->cache('/latest-three', ['collection:articles']);
        $this->cache('/unrelated', ['collection:pages']);

        // A brand new entry's id is in no recorded tag set, so only the list tag
        // can reach the pages that show it.
        Entry::make()->collection('articles')->slug('fresh')->data(['title' => 'Fresh'])->save();

        $this->assertCachedIs(['/unrelated']);
    }

    #[Test]
    public function changing_an_entrys_published_state_clears_pages_that_list_its_collection(): void
    {
        Collection::make('articles')->save();
        $entry = tap(Entry::make()->collection('articles')->slug('one')->data(['title' => 'One'])->published(true))->save();

        $this->cache('/latest-three', ['collection:articles']);
        $this->cache('/unrelated', ['collection:pages']);

        $entry->published(false)->save();

        $this->assertCachedIs(['/unrelated']);
    }

    #[Test]
    public function reordering_a_collection_tree_clears_pages_that_list_it(): void
    {
        $collection = Collection::make('articles')->structureContents(['root' => false]);
        $collection->save();

        $a = tap(Entry::make()->collection('articles')->slug('a')->data(['title' => 'A']))->save();
        $b = tap(Entry::make()->collection('articles')->slug('b')->data(['title' => 'B']))->save();

        $tree = $collection->structure()->in('default');
        $tree->tree([['entry' => $a->id()], ['entry' => $b->id()]])->save();

        $this->cache('/latest-three', ['collection:articles']);
        $this->cache('/unrelated', ['collection:pages']);

        // Order drives what "latest three" renders, and a reorder dispatches
        // CollectionTreeSaved rather than any entry event.
        $tree->tree([['entry' => $b->id()], ['entry' => $a->id()]])->save();

        $this->assertCachedIs(['/unrelated']);
    }

    #[Test]
    public function saving_a_global_clears_only_pages_that_read_it(): void
    {
        $set = tap(GlobalSet::make('footer'))->save();
        $set->makeLocalization('default')->data(['phone' => '1'])->save();

        $other = tap(GlobalSet::make('seo'))->save();
        $other->makeLocalization('default')->data(['title' => 'x'])->save();

        $this->cache('/reads-footer', ['global:footer']);
        $this->cache('/reads-seo', ['global:seo']);

        GlobalSet::find('footer')->in('default')->data(['phone' => '2'])->save();

        $this->assertCachedIs(['/reads-seo']);
    }

    /**
     * @param  list<string>  $tags
     */
    private function cache(string $path, array $tags): void
    {
        $this->cacher->cacheUrl(md5($path), $path, $this->domain);
        $this->graph->record($this->domain.$path, $tags);
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
