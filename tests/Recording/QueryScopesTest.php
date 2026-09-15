<?php

declare(strict_types=1);

namespace RoxDigital\CacheInvalidation\Tests\Recording;

use BadMethodCallException;
use PHPUnit\Framework\Attributes\Test;
use RoxDigital\CacheInvalidation\Tests\TestCase;
use Statamic\Facades\Collection;
use Statamic\Facades\Entry;
use Statamic\Facades\Taxonomy;
use Statamic\Facades\Term;
use Statamic\Query\Scopes\Scope;
use Statamic\Stache\Query\EntryQueryBuilder;
use Statamic\Stache\Query\TermQueryBuilder;

/**
 * Custom scopes belong to the site, not the addon, but Statamic files them under
 * the builder class they were registered against — so substituting a builder can
 * make a site's own scopes vanish. Registering directly against the Stache classes
 * here reproduces a site whose scopes were registered before this addon rebound the
 * builder, which is how the fatal was reported.
 *
 * Scoped queries are also read queries, so each case asserts the recording is still
 * right: a scope that narrows a list must not narrow what the page depends on, or a
 * later entry that would enter the list stops invalidating it.
 */
final class QueryScopesTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Collection::make('articles')->save();

        Entry::make()->collection('articles')->slug('one')
            ->data(['title' => 'One', 'featured' => true, 'topic' => 'design'])->save();
        Entry::make()->collection('articles')->slug('two')
            ->data(['title' => 'Two', 'featured' => false, 'topic' => 'design'])->save();
        Entry::make()->collection('articles')->slug('three')
            ->data(['title' => 'Three', 'featured' => true, 'topic' => 'code'])->save();

        Taxonomy::make('topics')->save();
        Term::make('design')->taxonomy('topics')->data(['title' => 'Design', 'featured' => true])->save();
        Term::make('code')->taxonomy('topics')->data(['title' => 'Code', 'featured' => true])->save();
        Term::make('other')->taxonomy('topics')->data(['title' => 'Other', 'featured' => false])->save();
    }

    #[Test]
    public function a_scope_registered_against_the_stache_entry_builder_still_applies(): void
    {
        $this->registerAgainst(EntryQueryBuilder::class, 'featured', Featured::class);

        $entries = Entry::query()->where('collection', 'articles')->featured()->get();

        $this->assertEqualsCanonicalizing(['One', 'Three'], $entries->map->title->all());
    }

    #[Test]
    public function several_scopes_chain_on_one_query(): void
    {
        $this->registerAgainst(EntryQueryBuilder::class, 'featured', Featured::class);
        $this->registerAgainst(EntryQueryBuilder::class, 'byTitle', ByTitle::class);
        $this->registerAgainst(EntryQueryBuilder::class, 'onlyOne', OnlyOne::class);

        $entries = Entry::query()->where('collection', 'articles')
            ->featured()
            ->byTitle()
            ->onlyOne()
            ->get();

        // Featured drops Two, byTitle orders One before Three, onlyOne takes one.
        $this->assertSame(['One'], $entries->map->title->all());
    }

    #[Test]
    public function a_scope_receives_the_values_it_was_called_with(): void
    {
        $this->registerAgainst(EntryQueryBuilder::class, 'inTopic', InTopic::class);

        $entries = Entry::query()->where('collection', 'articles')
            ->inTopic(['topic' => 'code'])
            ->get();

        $this->assertSame(['Three'], $entries->map->title->all());
    }

    #[Test]
    public function scopes_registered_against_the_parent_and_against_the_tracking_builder_mix(): void
    {
        // The realistic upgrade case: some scopes were filed under the Stache class
        // before this addon rebound the builder, others after.
        $this->registerAgainst(EntryQueryBuilder::class, 'featured', Featured::class);
        InTopic::register();
        Entry::allowQueryScope(InTopic::class, 'inTopic');

        $entries = Entry::query()->where('collection', 'articles')
            ->featured()
            ->inTopic(['topic' => 'design'])
            ->get();

        $this->assertSame(['One'], $entries->map->title->all());
    }

    #[Test]
    public function a_scoped_query_still_records_the_whole_collection(): void
    {
        $this->registerAgainst(EntryQueryBuilder::class, 'featured', Featured::class);
        $this->registerAgainst(EntryQueryBuilder::class, 'onlyOne', OnlyOne::class);

        $one = Entry::query()->where('slug', 'one')->first()->id();

        $tags = $this->tagsRecordedDuring(function () {
            Entry::query()->where('collection', 'articles')->featured()->onlyOne()->get();
        });

        // The list tag has to survive the narrowing, and only the rendered entry
        // gets an item tag.
        $this->assertContains('collection:articles', $tags);
        $this->assertContains("entry:{$one}", $tags);
    }

    #[Test]
    public function a_scope_applies_on_the_read_paths_that_bypass_get(): void
    {
        $this->registerAgainst(EntryQueryBuilder::class, 'featured', Featured::class);

        $query = fn () => Entry::query()->where('collection', 'articles')->featured();

        $this->assertSame(2, $query()->count());
        $this->assertEqualsCanonicalizing(['One', 'Three'], $query()->pluck('title')->all());
        $this->assertSame(2, $query()->paginate(10)->total());
    }

    #[Test]
    public function a_scope_reaches_the_builder_through_statamics_augmentation_wrappers(): void
    {
        // An entries field augments through OrderedQueryBuilder + StatusQueryBuilder,
        // which proxy unknown methods down to the real builder — so a scope called on
        // a relation, not on Entry::query(), lands on the tracking builder too.
        $this->registerAgainst(EntryQueryBuilder::class, 'featured', Featured::class);

        $this->blueprint('collections.pages', 'page', [
            'block' => ['type' => 'entries', 'collections' => ['articles']],
        ]);

        Collection::make('pages')->save();

        $ids = Entry::query()->where('collection', 'articles')->get()->map->id()->all();

        $page = tap(Entry::make()->collection('pages')->slug('home')
            ->data(['title' => 'Home', 'block' => $ids]))->save();

        $entries = $page->augmentedValue('block')->value()->featured()->get();

        $this->assertEqualsCanonicalizing(['One', 'Three'], $entries->map->title->all());
    }

    #[Test]
    public function several_term_scopes_chain_on_one_query(): void
    {
        $this->registerAgainst(TermQueryBuilder::class, 'featured', Featured::class);
        $this->registerAgainst(TermQueryBuilder::class, 'byTitle', ByTitle::class);

        $tags = $this->tagsRecordedDuring(function () use (&$terms) {
            $terms = Term::query()->where('taxonomy', 'topics')->featured()->byTitle()->get();
        });

        $this->assertSame(['Code', 'Design'], $terms->map->title->all());
        $this->assertContains('taxonomy:topics', $tags);
        $this->assertContains('term:topics::code', $tags);
    }

    #[Test]
    public function a_term_scope_allowed_through_the_repository_still_applies(): void
    {
        Featured::register();
        Term::allowQueryScope(Featured::class, 'featured');

        $terms = Term::query()->where('taxonomy', 'topics')->featured()->get();

        $this->assertCount(2, $terms);
    }

    #[Test]
    public function an_unregistered_method_still_throws(): void
    {
        $this->expectException(BadMethodCallException::class);
        $this->expectExceptionMessage('somethingNobodyRegistered');

        Entry::query()->somethingNobodyRegistered();
    }

    #[Test]
    public function a_scope_call_returns_the_builder_so_it_stays_chainable(): void
    {
        $this->registerAgainst(EntryQueryBuilder::class, 'featured', Featured::class);

        $query = Entry::query()->where('collection', 'articles');

        $this->assertSame($query, $query->featured());
    }

    private function registerAgainst(string $builder, string $method, string $scope): void
    {
        $scope::register();

        $scopes = app('statamic.query-scopes');

        $scopes->put($builder, $scopes->get($builder, collect())->put($method, $scope));
    }
}

class Featured extends Scope
{
    public static function handle()
    {
        return 'featured_scope';
    }

    public function apply($query, $values)
    {
        $query->where('featured', true);
    }
}

class ByTitle extends Scope
{
    public static function handle()
    {
        return 'by_title_scope';
    }

    public function apply($query, $values)
    {
        $query->orderBy('title', 'asc');
    }
}

class InTopic extends Scope
{
    public static function handle()
    {
        return 'in_topic_scope';
    }

    public function apply($query, $values)
    {
        $query->where('topic', $values['topic'] ?? null);
    }
}

class OnlyOne extends Scope
{
    public static function handle()
    {
        return 'only_one_scope';
    }

    public function apply($query, $values)
    {
        $query->limit(1);
    }
}
