<?php

declare(strict_types=1);

namespace RoxDigital\CacheInvalidation\Tests\Recording;

use PHPUnit\Framework\Attributes\Test;
use RoxDigital\CacheInvalidation\Facades\CacheTags;
use RoxDigital\CacheInvalidation\Tests\TestCase;
use Statamic\Facades\Collection;
use Statamic\Facades\Entry;
use Statamic\Facades\Nav;

final class WithoutRecordingTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Collection::make('pages')->save();
        Nav::make('main_nav')->title('Main')->save();

        Entry::make()->collection('pages')->id('one')->slug('one')->save();
        Entry::make()->collection('pages')->id('two')->slug('two')->save();
    }

    #[Test]
    public function it_drops_everything_read_inside_the_callback(): void
    {
        $tags = $this->tagsRecordedDuring(function (): void {
            CacheTags::withoutRecording(function (): void {
                Entry::find('one');
                Entry::find('two');
            });
        });

        $this->assertSame([], $tags);
    }

    #[Test]
    public function a_tag_added_outside_the_callback_survives(): void
    {
        $tags = $this->tagsRecordedDuring(function (): void {
            CacheTags::add('nav:main_nav');

            CacheTags::withoutRecording(fn () => Entry::find('one'));
        });

        $this->assertSame(['nav:main_nav'], $tags);
    }

    #[Test]
    public function recording_resumes_after_the_callback(): void
    {
        $tags = $this->tagsRecordedDuring(function (): void {
            CacheTags::withoutRecording(fn () => Entry::find('one'));

            Entry::find('two');
        });

        $this->assertSame(['entry:two'], $tags);
    }

    #[Test]
    public function it_returns_what_the_callback_returns(): void
    {
        $this->assertSame('menu', CacheTags::withoutRecording(fn (): string => 'menu'));
    }

    #[Test]
    public function nesting_does_not_resume_recording_early(): void
    {
        $tags = $this->tagsRecordedDuring(function (): void {
            CacheTags::withoutRecording(function (): void {
                CacheTags::withoutRecording(fn () => Entry::find('one'));

                // Still inside the outer suppression, so this must not record.
                Entry::find('two');
            });
        });

        $this->assertSame([], $tags);
    }

    #[Test]
    public function an_exception_still_restores_recording(): void
    {
        $tags = $this->tagsRecordedDuring(function (): void {
            try {
                CacheTags::withoutRecording(function (): void {
                    throw new \RuntimeException('menu blew up');
                });
            } catch (\RuntimeException) {
                // swallowed on purpose
            }

            Entry::find('two');
        });

        $this->assertSame(['entry:two'], $tags);
    }
}
