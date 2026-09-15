<?php

declare(strict_types=1);

namespace RoxDigital\CacheInvalidation\Tests\Invalidation;

use Mockery;
use PHPUnit\Framework\Attributes\Test;
use RoxDigital\CacheInvalidation\CachedUrls;
use RoxDigital\CacheInvalidation\Invalidation\GraphInvalidator;
use RoxDigital\CacheInvalidation\Invalidation\TagResolver;
use RoxDigital\CacheInvalidation\Tests\TestCase;
use Statamic\Contracts\Entries\Entry as EntryContract;
use Statamic\Facades\Collection;
use Statamic\Facades\Entry;
use Statamic\StaticCaching\Cacher;

/**
 * What the invalidator hands the cacher, rather than what the cacher then does.
 *
 * The graph outlives the cache by design — rows are pruned as they are
 * invalidated, not when a page falls out — so a tag on a site that has been up
 * for a while resolves to far more URLs than are actually cached. Passing the
 * uncached ones on costs a lookup that can only miss, and there are usually more
 * of them than there are real hits.
 */
final class NarrowsToCachedUrlsTest extends TestCase
{
    #[Test]
    public function it_does_not_hand_over_graph_urls_that_are_no_longer_cached(): void
    {
        $entry = $this->article();

        $cacher = app(Cacher::class);
        $domain = $cacher->getBaseUrl();

        // Cached after the save, so the save's own invalidation cannot clear it.
        $cacher->cacheUrl(md5('/still-cached'), '/still-cached', $domain);

        $this->graph->record($domain.'/still-cached', ["entry:{$entry->id()}"]);
        $this->graph->record($domain.'/long-gone', ["entry:{$entry->id()}"]);

        $handed = $this->urlsHandedToCacher($entry, new CachedUrls($cacher));

        $this->assertContains($domain.'/still-cached', $handed);
        $this->assertNotContains($domain.'/long-gone', $handed);
    }

    #[Test]
    public function it_hands_over_everything_when_the_cache_cannot_be_enumerated(): void
    {
        // CachedUrls gives up on any cacher that is not an AbstractCacher, and an
        // empty list is indistinguishable from a freshly flushed site. Reading it
        // as "nothing is cached" would clear nothing at all on a host app's own
        // cacher — silently serving stale pages, the one failure that matters.
        $entry = $this->article();

        $domain = app(Cacher::class)->getBaseUrl();

        $this->graph->record($domain.'/long-gone', ["entry:{$entry->id()}"]);

        $handed = $this->urlsHandedToCacher($entry, new CachedUrls(Mockery::mock(Cacher::class)));

        $this->assertContains($domain.'/long-gone', $handed);
    }

    private function article(): EntryContract
    {
        Collection::make('articles')->save();

        return tap(Entry::make()->collection('articles')->slug('one')->data(['title' => 'One']))->save();
    }

    /**
     * Invalidates through a cacher that only records its arguments, so the
     * assertion is about the list itself and not about what survived in a cache.
     *
     * @return list<string>
     */
    private function urlsHandedToCacher(EntryContract $entry, CachedUrls $cached): array
    {
        $handed = [];

        $cacher = Mockery::mock(Cacher::class);
        $cacher->shouldReceive('invalidateUrls')->once()->with(Mockery::capture($handed));

        (new GraphInvalidator($cacher, [], $this->graph, app(TagResolver::class), $cached))
            ->invalidate($entry);

        return $handed;
    }
}
