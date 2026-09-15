<?php

declare(strict_types=1);

namespace RoxDigital\CacheInvalidation\Tests\Graph;

use PHPUnit\Framework\Attributes\Test;
use RoxDigital\CacheInvalidation\Graph\SqliteGraph;
use RoxDigital\CacheInvalidation\Tests\TestCase;

final class SqliteGraphTest extends TestCase
{
    #[Test]
    public function it_creates_its_own_schema_without_a_configured_database(): void
    {
        config(['database.default' => 'nonexistent']);

        $this->graph->record('https://site.test/a', ['entry:1']);

        $this->assertSame(['entry:1'], $this->graph->tagsFor('https://site.test/a'));
        $this->assertInstanceOf(SqliteGraph::class, $this->graph);
    }

    #[Test]
    public function it_finds_urls_by_any_of_their_tags(): void
    {
        $this->graph->record('https://site.test/a', ['entry:1', 'global:footer']);
        $this->graph->record('https://site.test/b', ['entry:2', 'global:footer']);
        $this->graph->record('https://site.test/c', ['entry:3']);

        $this->assertSame(['https://site.test/a'], $this->graph->urlsFor(['entry:1']));

        $this->assertEqualsCanonicalizing(
            ['https://site.test/a', 'https://site.test/b'],
            $this->graph->urlsFor(['global:footer']),
        );

        // A URL matching more than one of the given tags appears once, and an
        // unknown tag contributes nothing.
        $matched = $this->graph->urlsFor(['entry:1', 'global:footer', 'entry:missing']);

        $this->assertEqualsCanonicalizing(['https://site.test/a', 'https://site.test/b'], $matched);
        $this->assertSame(1, count(array_keys($matched, 'https://site.test/a', true)));
    }

    #[Test]
    public function recording_replaces_a_urls_previous_tags_rather_than_adding_to_them(): void
    {
        $this->graph->record('https://site.test/a', ['entry:1', 'entry:2']);
        $this->graph->record('https://site.test/a', ['entry:3']);

        $this->assertSame(['entry:3'], $this->graph->tagsFor('https://site.test/a'));
        $this->assertSame([], $this->graph->urlsFor(['entry:1']));
    }

    #[Test]
    public function recording_an_empty_tag_set_leaves_the_url_untracked(): void
    {
        $this->graph->record('https://site.test/a', ['entry:1']);
        $this->graph->record('https://site.test/a', []);

        $this->assertSame([], $this->graph->tagsFor('https://site.test/a'));

        // Untracked rather than "depends on nothing" — the safety net must still
        // reach it, or a page whose recording failed would stay stale forever.
        $this->assertSame(
            ['https://site.test/a'],
            $this->graph->untracked(['https://site.test/a']),
        );
    }

    #[Test]
    public function untracked_returns_only_urls_absent_from_the_graph(): void
    {
        $this->graph->record('https://site.test/known', ['entry:1']);

        $this->assertSame(
            ['https://site.test/unknown'],
            $this->graph->untracked(['https://site.test/known', 'https://site.test/unknown']),
        );

        $this->assertSame([], $this->graph->untracked([]));
    }

    #[Test]
    public function it_distinguishes_urls_that_differ_only_in_their_query_string(): void
    {
        // Identity is a hash, so a truncating index would collapse these.
        $long = 'https://site.test/'.str_repeat('a', 200);

        $this->graph->record($long.'?page=1', ['entry:1']);
        $this->graph->record($long.'?page=2', ['entry:2']);

        $this->assertSame(['entry:1'], $this->graph->tagsFor($long.'?page=1'));
        $this->assertSame(['entry:2'], $this->graph->tagsFor($long.'?page=2'));
    }

    #[Test]
    public function it_handles_more_tags_than_fit_in_one_insert(): void
    {
        $tags = array_map(fn (int $i): string => "entry:{$i}", range(1, 1200));

        $this->graph->record('https://site.test/big', $tags);

        $this->assertCount(1200, $this->graph->tagsFor('https://site.test/big'));
        $this->assertSame(['https://site.test/big'], $this->graph->urlsFor(['entry:1200']));
    }

    #[Test]
    public function forget_and_flush_remove_rows(): void
    {
        $this->graph->record('https://site.test/a', ['entry:1']);
        $this->graph->record('https://site.test/b', ['entry:2']);

        $this->graph->forget('https://site.test/a');
        $this->assertSame(['https://site.test/b'], $this->graph->urls());

        $this->graph->flush();
        $this->assertSame([], $this->graph->urls());
        $this->assertSame(['urls' => 0, 'tags' => 0, 'rows' => 0], $this->graph->stats());
    }
}
