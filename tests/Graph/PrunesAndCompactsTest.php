<?php

declare(strict_types=1);

namespace RoxDigital\CacheInvalidation\Tests\Graph;

use PHPUnit\Framework\Attributes\Test;
use RoxDigital\CacheInvalidation\Tests\TestCase;

final class PrunesAndCompactsTest extends TestCase
{
    #[Test]
    public function it_drops_urls_that_are_no_longer_cached(): void
    {
        $this->graph->record('https://site.test/kept', ['entry:1', 'global:footer']);
        $this->graph->record('https://site.test/gone', ['entry:2', 'global:footer']);

        $removed = $this->graph->prune(['https://site.test/kept']);

        $this->assertSame(2, $removed);
        $this->assertSame(['https://site.test/kept'], $this->graph->urls());
        $this->assertSame(['entry:1', 'global:footer'], $this->graph->tagsFor('https://site.test/kept'));
        $this->assertSame([], $this->graph->tagsFor('https://site.test/gone'));
    }

    #[Test]
    public function it_keeps_everything_when_every_url_is_still_cached(): void
    {
        $this->graph->record('https://site.test/a', ['entry:1']);
        $this->graph->record('https://site.test/b', ['entry:2']);

        $removed = $this->graph->prune(['https://site.test/a', 'https://site.test/b']);

        $this->assertSame(0, $removed);
        $this->assertSame(['https://site.test/a', 'https://site.test/b'], $this->graph->urls());
    }

    #[Test]
    public function pruning_against_an_empty_cache_empties_the_graph(): void
    {
        $this->graph->record('https://site.test/a', ['entry:1']);

        $this->assertSame(1, $this->graph->prune([]));
        $this->assertSame([], $this->graph->urls());
    }

    /**
     * The reason prune() resolves the stale set in PHP: chunking a NOT IN would
     * make every chunk delete what the other chunks were keeping.
     */
    #[Test]
    public function it_prunes_correctly_across_more_urls_than_one_query_chunk(): void
    {
        $urls = [];

        for ($i = 0; $i < 1200; $i++) {
            $urls[] = $url = "https://site.test/page-{$i}";
            $this->graph->record($url, ['entry:'.$i]);
        }

        $keep = array_slice($urls, 0, 600);

        $this->graph->prune($keep);

        $this->assertSame(600, count($this->graph->urls()));
        $this->assertSame(['entry:0'], $this->graph->tagsFor($urls[0]));
        $this->assertSame([], $this->graph->tagsFor($urls[1199]));
    }

    #[Test]
    public function compacting_reclaims_the_file_and_leaves_the_rows_alone(): void
    {
        for ($i = 0; $i < 4000; $i++) {
            $this->graph->record("https://site.test/page-{$i}", ['entry:'.$i, 'global:footer']);
        }

        $this->graph->prune(['https://site.test/page-0']);

        $bloated = $this->sqliteSize();

        $this->graph->compact();

        $this->assertLessThan($bloated, $this->sqliteSize(), 'VACUUM should shorten the file.');
        $this->assertSame(['entry:0', 'global:footer'], $this->graph->tagsFor('https://site.test/page-0'));
    }

    #[Test]
    public function compacting_an_untouched_graph_is_harmless(): void
    {
        $this->graph->record('https://site.test/a', ['entry:1']);

        $this->graph->compact();

        $this->assertSame(['entry:1'], $this->graph->tagsFor('https://site.test/a'));
    }

    private function sqliteSize(): int
    {
        clearstatcache(true, $path = $this->sqlitePath());

        return is_file($path) ? (int) filesize($path) : 0;
    }
}
