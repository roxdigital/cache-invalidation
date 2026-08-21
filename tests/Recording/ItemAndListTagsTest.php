<?php

declare(strict_types=1);

namespace RoxDigital\CacheInvalidation\Tests\Recording;

use PHPUnit\Framework\Attributes\Test;
use RoxDigital\CacheInvalidation\Tests\TestCase;
use Statamic\Facades\Collection;
use Statamic\Facades\Entry;

/**
 * The item-tag versus list-tag rule, exercised through real queries against real
 * entries.
 *
 * Deliberately not asserted against synthetic where clauses. A shipped bug proved
 * why: Statamic's entries fieldtype augments through a StatusQueryBuilder whose
 * status filter adds a nested clause *only when the queried ids actually resolve*.
 * With invented ids the query looks simple and the old implementation passed; with
 * real content it leaked a list tag. Tests that build queries by hand would have
 * agreed with the bug.
 */
final class ItemAndListTagsTest extends TestCase
{
    private string $one;

    private string $two;

    protected function setUp(): void
    {
        parent::setUp();

        Collection::make('articles')->save();
        Collection::make('authors')->save();

        $author = tap(Entry::make()->collection('authors')->slug('ada')->data(['title' => 'Ada']))->save();

        $this->one = tap(Entry::make()->collection('articles')->slug('one')
            ->data(['title' => 'One', 'author' => $author->id()]))->save()->id();

        $this->two = tap(Entry::make()->collection('articles')->slug('two')
            ->data(['title' => 'Two', 'author' => $author->id()]))->save()->id();
    }

    #[Test]
    public function finding_an_entry_by_id_records_only_that_entry(): void
    {
        $tags = $this->tagsRecordedDuring(fn () => Entry::find($this->one));

        $this->assertSame(["entry:{$this->one}"], $tags);
    }

    #[Test]
    public function an_augmented_entries_field_records_only_the_referenced_entry(): void
    {
        // The reusable-block case, and the one that regressed. Augmentation runs
        // through OrderedQueryBuilder + StatusQueryBuilder, and the status filter
        // back-fills the collection from the queried ids before adding a nested
        // clause — which must not be mistaken for a list query.
        $this->blueprint('collections.pages', 'page', [
            'block' => ['type' => 'entries', 'max_items' => 1, 'collections' => ['articles']],
        ]);

        Collection::make('pages')->save();

        $page = tap(Entry::make()->collection('pages')->slug('home')
            ->data(['title' => 'Home', 'block' => [$this->one]]))->save();

        $tags = $this->tagsRecordedDuring(function () use ($page): void {
            $resolved = $page->augmentedValue('block')->value();

            // Force the lazy query builder to run, as rendering would.
            $resolved instanceof \Statamic\Contracts\Entries\Entry ? $resolved->id() : collect($resolved)->first();
        });

        $this->assertContains("entry:{$this->one}", $tags);
        $this->assertNotContains('collection:articles', $tags, 'an id-pinned lookup must not record a list tag');
    }

    #[Test]
    public function an_unfiltered_collection_query_records_the_list_tag_and_every_entry(): void
    {
        $tags = $this->tagsRecordedDuring(fn () => Entry::query()->where('collection', 'articles')->get());

        $this->assertContains('collection:articles', $tags);
        $this->assertContains("entry:{$this->one}", $tags);
        $this->assertContains("entry:{$this->two}", $tags);
    }

    #[Test]
    public function a_limited_query_records_the_list_tag_and_only_the_entries_it_returned(): void
    {
        $tags = $this->tagsRecordedDuring(
            fn () => Entry::query()->where('collection', 'articles')->orderBy('slug', 'asc')->limit(1)->get(),
        );

        // The list tag is what clears this page when a third article is created;
        // its id could not possibly be in the recorded set.
        $this->assertContains('collection:articles', $tags);
        $this->assertContains("entry:{$this->one}", $tags);
        $this->assertNotContains("entry:{$this->two}", $tags);
    }

    #[Test]
    public function a_query_filtered_on_a_normal_field_records_the_list_tag(): void
    {
        $author = Entry::query()->where('collection', 'authors')->first();

        $tags = $this->tagsRecordedDuring(
            fn () => Entry::query()->where('collection', 'articles')->where('author', $author->id())->get(),
        );

        $this->assertContains('collection:articles', $tags);
    }

    #[Test]
    public function count_records_the_list_tag(): void
    {
        // count() bypasses get() entirely in Stache\Query\Builder, so a recorder
        // hooked on get() alone would silently miss it.
        $tags = $this->tagsRecordedDuring(fn () => Entry::query()->where('collection', 'articles')->count());

        $this->assertContains('collection:articles', $tags);
    }

    #[Test]
    public function pluck_records_the_list_tag(): void
    {
        $tags = $this->tagsRecordedDuring(fn () => Entry::query()->where('collection', 'articles')->pluck('title'));

        $this->assertContains('collection:articles', $tags);
    }

    #[Test]
    public function whereIn_on_ids_records_only_those_entries(): void
    {
        $tags = $this->tagsRecordedDuring(
            fn () => Entry::query()->whereIn('id', [$this->one, $this->two])->get(),
        );

        $this->assertEqualsCanonicalizing(["entry:{$this->one}", "entry:{$this->two}"], $tags);
    }

    #[Test]
    public function excluding_ids_records_the_list_tag(): void
    {
        // whereNotIn excludes ids rather than bounding results to them, so an
        // entry created later would appear and the page must react to it.
        $tags = $this->tagsRecordedDuring(
            fn () => Entry::query()->where('collection', 'articles')->whereNotIn('id', [$this->one])->get(),
        );

        $this->assertContains('collection:articles', $tags);
    }

    #[Test]
    public function an_or_clause_alongside_an_id_records_the_list_tag(): void
    {
        // An OR can admit rows from outside the id set, so the query is no longer
        // bounded by it.
        $tags = $this->tagsRecordedDuring(
            fn () => Entry::query()
                ->where('collection', 'articles')
                ->where('id', $this->one)
                ->orWhere('title', 'Two')
                ->get(),
        );

        $this->assertContains('collection:articles', $tags);
    }

    #[Test]
    public function an_unscoped_query_records_every_collection(): void
    {
        $tags = $this->tagsRecordedDuring(fn () => Entry::query()->where('title', 'One')->get());

        $this->assertContains('collection:articles', $tags);
        $this->assertContains('collection:authors', $tags);
    }
}
