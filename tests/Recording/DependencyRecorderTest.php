<?php

declare(strict_types=1);

namespace RoxDigital\CacheInvalidation\Tests\Recording;

use PHPUnit\Framework\Attributes\Test;
use RoxDigital\CacheInvalidation\Tag;
use RoxDigital\CacheInvalidation\Tests\TestCase;

final class DependencyRecorderTest extends TestCase
{
    #[Test]
    public function it_deduplicates_and_ignores_empty_tags(): void
    {
        $this->recorder->add('entry:1', 'entry:1', '', 'entry:2');

        $this->assertSame(['entry:1', 'entry:2'], $this->recorder->tags());
    }

    #[Test]
    public function reads_accumulate_flatly_regardless_of_nesting(): void
    {
        // There is no notion of "which block am I inside": a reusable block's reads
        // are the embedding page's reads, at any depth. This is what makes
        // transitive dependencies work without expansion logic.
        $this->recorder->entries(['a']);
        $this->recorder->globalSet('footer');
        $this->recorder->entries(['b']);
        $this->recorder->form('contact');

        $this->assertSame(
            ['entry:a', 'global:footer', 'entry:b', 'form:contact'],
            $this->recorder->tags(),
        );
    }

    #[Test]
    public function suppressed_drops_everything_recorded_inside_it(): void
    {
        $this->recorder->add('entry:before');

        $returned = $this->recorder->suppressed(function (): string {
            $this->recorder->add('entry:inside');
            $this->recorder->collections(['pages']);

            return 'result';
        });

        $this->recorder->add('entry:after');

        $this->assertSame('result', $returned, 'the callback result is passed through');
        $this->assertSame(['entry:before', 'entry:after'], $this->recorder->tags());
    }

    #[Test]
    public function suppression_is_lifted_even_when_the_callback_throws(): void
    {
        try {
            $this->recorder->suppressed(fn () => throw new \RuntimeException('boom'));
        } catch (\RuntimeException) {
            // expected
        }

        $this->recorder->add('entry:after');

        $this->assertSame(['entry:after'], $this->recorder->tags());
    }

    #[Test]
    public function suppression_nests(): void
    {
        $this->recorder->suppressed(function (): void {
            $this->recorder->suppressed(fn () => $this->recorder->add('entry:inner'));
            $this->recorder->add('entry:outer');
        });

        $this->recorder->add('entry:after');

        $this->assertSame(['entry:after'], $this->recorder->tags());
    }

    #[Test]
    public function it_collapses_to_the_overflow_tag_past_the_cap(): void
    {
        $this->recorder->entries(array_map(fn (int $i): string => (string) $i, range(1, 2_500)));

        // Lossy in the safe direction: the page is now cleared by any content
        // save, rather than silently keeping a truncated tag set that would leave
        // it stale.
        $this->assertSame([Tag::OVERFLOW], $this->recorder->tags());
        $this->assertTrue($this->recorder->overflowed());
    }

    #[Test]
    public function an_overflowed_set_stays_overflowed(): void
    {
        $this->recorder->entries(array_map(fn (int $i): string => (string) $i, range(1, 2_500)));
        $this->recorder->globalSet('footer');

        $this->assertSame([Tag::OVERFLOW], $this->recorder->tags());
    }

    #[Test]
    public function reset_clears_the_overflow_flag_too(): void
    {
        $this->recorder->entries(array_map(fn (int $i): string => (string) $i, range(1, 2_500)));
        $this->recorder->reset();
        $this->recorder->entries(['a']);

        $this->assertSame(['entry:a'], $this->recorder->tags());
        $this->assertFalse($this->recorder->overflowed());
    }

    #[Test]
    public function it_ignores_non_string_and_blank_identifiers(): void
    {
        $this->recorder->entries(['a', null, 42, '']);
        $this->recorder->collections(['pages', null, '']);

        $this->assertSame(['entry:a', 'collection:pages'], $this->recorder->tags());
    }
}
