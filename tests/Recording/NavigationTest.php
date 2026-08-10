<?php

declare(strict_types=1);

namespace RoxDigital\CacheInvalidation\Tests\Recording;

use PHPUnit\Framework\Attributes\Test;
use RoxDigital\CacheInvalidation\Tests\TestCase;
use Statamic\Facades\Nav;

final class NavigationTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Nav::make('main_nav')->title('Main')->save();
        Nav::make('footer_nav')->title('Footer')->save();
    }

    #[Test]
    public function resolving_a_nav_by_handle_records_it(): void
    {
        // Statamic's nav tag reaches this through Tags\Structure::structure().
        $tags = $this->tagsRecordedDuring(fn () => Nav::findByHandle('main_nav'));

        $this->assertSame(['nav:main_nav'], $tags);
    }

    #[Test]
    public function find_records_it_too(): void
    {
        $this->assertSame(['nav:main_nav'], $this->tagsRecordedDuring(fn () => Nav::find('main_nav')));
    }

    #[Test]
    public function resolving_one_nav_does_not_record_another(): void
    {
        $tags = $this->tagsRecordedDuring(fn () => Nav::findByHandle('footer_nav'));

        $this->assertSame(['nav:footer_nav'], $tags);
        $this->assertNotContains('nav:main_nav', $tags);
    }

    #[Test]
    public function resolving_a_missing_nav_records_nothing(): void
    {
        $this->assertSame([], $this->tagsRecordedDuring(fn () => Nav::findByHandle('nope')));
    }

    #[Test]
    public function listing_every_nav_records_all_of_them(): void
    {
        // Over-recording on purpose. A nav that goes unrecorded means a visitor
        // keeps seeing a removed menu item, which is the one failure that shows;
        // recording too many only clears more pages than strictly necessary.
        $tags = $this->tagsRecordedDuring(fn () => Nav::all());

        $this->assertContains('nav:main_nav', $tags);
        $this->assertContains('nav:footer_nav', $tags);
    }

    #[Test]
    public function resolving_a_nav_tree_records_the_nav(): void
    {
        // A second, independent recording point, for a template that already holds
        // a Nav object and never goes back through the repository.
        $tags = $this->tagsRecordedDuring(function (): void {
            Nav::findByHandle('main_nav')->in('default');
        });

        $this->assertContains('nav:main_nav', $tags);
    }
}
