<?php

declare(strict_types=1);

namespace RoxDigital\CacheInvalidation\Tests\Console;

use PHPUnit\Framework\Attributes\Test;
use RoxDigital\CacheInvalidation\Tests\TestCase;
use Statamic\Facades\Collection;
use Statamic\Facades\Entry;
use Statamic\Facades\Nav;

final class SelfTestCommandTest extends TestCase
{
    #[Test]
    public function it_passes_against_the_statamic_version_it_was_built_for(): void
    {
        Collection::make('articles')->save();
        Entry::make()->collection('articles')->slug('one')->data(['title' => 'One'])->save();
        Nav::make('main_nav')->title('Main')->save();
        Nav::find('main_nav')->makeTree('default')->save();

        $this->artisan('cache-invalidation:selftest')
            ->expectsOutputToContain('The addon fits this Statamic version')
            ->assertSuccessful();
    }

    #[Test]
    public function it_skips_checks_that_need_content_the_site_does_not_have(): void
    {
        // An empty site must not report failure — there is nothing wrong with it,
        // there is just nothing to check.
        $this->artisan('cache-invalidation:selftest')
            ->expectsOutputToContain('skipped')
            ->assertSuccessful();
    }

    #[Test]
    public function it_fails_when_a_seam_no_longer_behaves(): void
    {
        Collection::make('articles')->save();
        Entry::make()->collection('articles')->slug('one')->data(['title' => 'One'])->save();

        // Stand in for the addon no longer being wired in — which is what a Statamic
        // change that moves a binding out from under it looks like from the site.
        $this->app->bind(
            \Statamic\StaticCaching\Invalidator::class,
            fn ($app) => new \Statamic\StaticCaching\DefaultInvalidator($app[\Statamic\StaticCaching\Cacher::class], []),
        );

        $this->artisan('cache-invalidation:selftest')
            ->expectsOutputToContain('does not fit this Statamic version')
            ->assertFailed();
    }
}
