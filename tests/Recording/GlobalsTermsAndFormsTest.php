<?php

declare(strict_types=1);

namespace RoxDigital\CacheInvalidation\Tests\Recording;

use PHPUnit\Framework\Attributes\Test;
use RoxDigital\CacheInvalidation\Tests\TestCase;
use Statamic\Facades\Form;
use Statamic\Facades\GlobalSet;
use Statamic\Facades\Taxonomy;
use Statamic\Facades\Term;

final class GlobalsTermsAndFormsTest extends TestCase
{
    #[Test]
    public function a_global_set_is_recorded_only_once_one_of_its_values_is_read(): void
    {
        $this->makeGlobalSet('footer', ['phone' => '123']);

        // Statamic hydrates every global set into every view whether a template
        // touches it or not. Recording at hydration would mark every page as
        // depending on every global, which is the behaviour this replaces.
        $onFetch = $this->tagsRecordedDuring(fn () => GlobalSet::find('footer')->in('default'));

        $this->assertSame([], $onFetch, 'fetching a global set must not record it');

        $onRead = $this->tagsRecordedDuring(function (): void {
            $variables = GlobalSet::find('footer')->in('default');
            $variables->phone;
        });

        $this->assertSame(['global:footer'], $onRead);
    }

    #[Test]
    public function reading_one_global_does_not_record_another(): void
    {
        $this->makeGlobalSet('footer', ['phone' => '123']);
        $this->makeGlobalSet('seo', ['title' => 'Site']);

        $tags = $this->tagsRecordedDuring(function (): void {
            GlobalSet::find('footer')->in('default')->phone;
        });

        $this->assertSame(['global:footer'], $tags);
        $this->assertNotContains('global:seo', $tags);
    }

    #[Test]
    public function a_taxonomy_query_records_the_taxonomy_and_each_term(): void
    {
        Taxonomy::make('topics')->save();
        Term::make('news')->taxonomy('topics')->data(['title' => 'News'])->save();
        Term::make('events')->taxonomy('topics')->data(['title' => 'Events'])->save();

        $tags = $this->tagsRecordedDuring(fn () => Term::query()->where('taxonomy', 'topics')->get());

        $this->assertContains('taxonomy:topics', $tags);
        $this->assertContains('term:topics::news', $tags);
        $this->assertContains('term:topics::events', $tags);
    }

    #[Test]
    public function finding_a_term_by_id_records_only_that_term(): void
    {
        Taxonomy::make('topics')->save();
        Term::make('news')->taxonomy('topics')->data(['title' => 'News'])->save();
        Term::make('events')->taxonomy('topics')->data(['title' => 'Events'])->save();

        $tags = $this->tagsRecordedDuring(fn () => Term::find('topics::news'));

        $this->assertSame(['term:topics::news'], $tags);
        $this->assertNotContains('taxonomy:topics', $tags);
    }

    #[Test]
    public function resolving_a_form_records_it(): void
    {
        Form::make('contact')->save();

        $tags = $this->tagsRecordedDuring(fn () => Form::find('contact'));

        $this->assertSame(['form:contact'], $tags);
    }

    #[Test]
    public function listing_every_form_records_nothing(): void
    {
        Form::make('contact')->save();
        Form::make('newsletter')->save();

        // FormRepository::all() resolves through self::find(), binding to the
        // parent class. That is deliberate: all() is control panel territory and
        // must not mark a page as depending on every form on the site.
        $tags = $this->tagsRecordedDuring(fn () => Form::all());

        $this->assertSame([], $tags);
    }

    #[Test]
    public function resolving_a_missing_form_records_nothing(): void
    {
        $this->assertSame([], $this->tagsRecordedDuring(fn () => Form::find('nope')));
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function makeGlobalSet(string $handle, array $data): void
    {
        $set = tap(GlobalSet::make($handle))->save();

        $set->makeLocalization('default')->data($data)->save();
    }
}
