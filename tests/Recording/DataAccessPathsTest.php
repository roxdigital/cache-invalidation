<?php

declare(strict_types=1);

namespace RoxDigital\CacheInvalidation\Tests\Recording;

use PHPUnit\Framework\Attributes\Test;
use RoxDigital\CacheInvalidation\Tests\TestCase;
use Statamic\Facades\Collection;
use Statamic\Facades\Entry;
use Statamic\Facades\Form;
use Statamic\Facades\GlobalSet;
use Statamic\Facades\Taxonomy;
use Statamic\Facades\Term;
use Statamic\Statamic;

/**
 * Recording hooks repositories and query builders rather than templates, so it
 * should not matter how a template asks for content. This asserts that for each way
 * of asking, since "it goes through the repository" is easy to claim and easy to be
 * wrong about.
 */
final class DataAccessPathsTest extends TestCase
{
    private string $entryId;

    protected function setUp(): void
    {
        parent::setUp();

        Collection::make('articles')->save();
        $this->entryId = tap(Entry::make()->collection('articles')->slug('one')
            ->data(['title' => 'One']))->save()->id();

        Taxonomy::make('topics')->save();
        Term::make('news')->taxonomy('topics')->data(['title' => 'News'])->save();

        Form::make('contact')->save();

        $set = tap(GlobalSet::make('footer'))->save();
        $set->makeLocalization('default')->data(['phone' => '123'])->save();
    }

    #[Test]
    public function a_plain_php_query_is_recorded(): void
    {
        // The @php block or view model case.
        $tags = $this->tagsRecordedDuring(
            fn () => Entry::query()->where('collection', 'articles')->get(),
        );

        $this->assertContains('collection:articles', $tags);
        $this->assertContains("entry:{$this->entryId}", $tags);
    }

    #[Test]
    public function the_antlers_collection_tag_is_recorded(): void
    {
        $tags = $this->tagsRecordedDuring(fn () => Statamic::tag('collection:articles')->fetch());

        $this->assertContains('collection:articles', $tags);
        $this->assertContains("entry:{$this->entryId}", $tags);
    }

    #[Test]
    public function the_antlers_taxonomy_tag_is_recorded(): void
    {
        $tags = $this->tagsRecordedDuring(fn () => Statamic::tag('taxonomy:topics')->fetch());

        $this->assertContains('taxonomy:topics', $tags);
        $this->assertContains('term:topics::news', $tags);
    }

    #[Test]
    public function a_globals_read_through_the_cascade_is_recorded(): void
    {
        // How a Blade layout reaches a global: {{ $footer->phone }}.
        $tags = $this->tagsRecordedDuring(function (): void {
            GlobalSet::find('footer')->in('default')->phone;
        });

        $this->assertSame(['global:footer'], $tags);
    }

    #[Test]
    public function an_augmented_field_is_recorded(): void
    {
        // How a pagebuilder block reaches a relation: $block->entry.
        $this->blueprint('collections.pages', 'page', [
            'related' => ['type' => 'entries', 'collections' => ['articles']],
        ]);

        Collection::make('pages')->save();
        $page = tap(Entry::make()->collection('pages')->slug('home')
            ->data(['title' => 'Home', 'related' => [$this->entryId]]))->save();

        $tags = $this->tagsRecordedDuring(function () use ($page): void {
            // An entries field augments to a lazy query builder, so it has to be
            // run — which is exactly what iterating it in a template does.
            $page->augmentedValue('related')->value()->get()->first()?->id();
        });

        $this->assertContains("entry:{$this->entryId}", $tags);
    }

    #[Test]
    public function the_form_tag_records_the_form(): void
    {
        $tags = $this->tagsRecordedDuring(fn () => Statamic::tag('form:create')->params(['in' => 'contact'])->fetch());

        $this->assertContains('form:contact', $tags);
    }

    #[Test]
    public function reading_content_outside_a_repository_is_not_recorded(): void
    {
        // The boundary, stated as a test: recording follows the repositories, so
        // anything that sidesteps them — an HTTP call, a file, a custom model — is
        // invisible and needs CacheTags::add(). This is what @cachetags is for.
        $tags = $this->tagsRecordedDuring(function (): void {
            json_decode('{"reviews": 5}', true);
        });

        $this->assertSame([], $tags);
    }
}
