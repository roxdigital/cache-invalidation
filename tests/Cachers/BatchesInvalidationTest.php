<?php

declare(strict_types=1);

namespace RoxDigital\CacheInvalidation\Tests\Cachers;

use Illuminate\Cache\Events\KeyWritten;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Queue;
use PHPUnit\Framework\Attributes\Test;
use RoxDigital\CacheInvalidation\Tests\TestCase;
use Statamic\StaticCaching\Cacher;

/**
 * The cost of a bulk invalidation, not its outcome.
 *
 * Statamic keeps one URL map per domain and rewrites all of it for every URL it
 * clears, so a targeted invalidation that names a few thousand URLs spends its
 * time rewriting the same cache entry rather than clearing pages. Past a point
 * the queued job is killed on its timeout and the URLs it never reached stay
 * stale, which looks to an editor like the addon ignoring a save.
 *
 * Each test therefore asserts the number of URL-map writes alongside the
 * resulting cache state: the map has to end up correct *and* be written once.
 */
final class BatchesInvalidationTest extends TestCase
{
    private Cacher $cacher;

    private string $domain;

    protected function setUp(): void
    {
        parent::setUp();

        $this->cacher = app(Cacher::class);
        $this->domain = $this->cacher->getBaseUrl();
    }

    #[Test]
    public function it_writes_the_url_map_once_however_many_urls_are_cleared(): void
    {
        foreach (range(1, 50) as $i) {
            $this->cache("/page-{$i}");
        }

        $writes = $this->urlMapWritesDuring(fn () => $this->cacher->invalidateUrls(
            array_map(fn (int $i): string => $this->domain."/page-{$i}", range(1, 40)),
        ));

        $this->assertSame(1, $writes);
        $this->assertCachedIs(array_map(fn (int $i): string => "/page-{$i}", range(41, 50)));
    }

    #[Test]
    public function it_does_not_rewrite_the_url_map_when_nothing_matched(): void
    {
        $this->cache('/kept');

        $writes = $this->urlMapWritesDuring(fn () => $this->cacher->invalidateUrls([
            $this->domain.'/never-cached',
        ]));

        $this->assertSame(0, $writes);
        $this->assertCachedIs(['/kept']);
    }

    #[Test]
    public function it_clears_query_string_variants_in_the_same_pass(): void
    {
        // The reason a single save can name thousands of URLs: every allowed query
        // string is its own cache entry, and a crawled paginated listing produces
        // hundreds of them under one path.
        $this->cache('/list');
        $this->cache('/list?page=2');
        $this->cache('/list?page=3');
        $this->cache('/other');

        $writes = $this->urlMapWritesDuring(fn () => $this->cacher->invalidateUrls([
            $this->domain.'/list',
        ]));

        $this->assertSame(1, $writes);
        $this->assertCachedIs(['/other']);
    }

    #[Test]
    public function it_writes_one_url_map_per_domain_touched(): void
    {
        $other = 'https://other.test';

        $this->cache('/a');
        $this->cache('/b');
        $this->cache('/a', $other);
        $this->cache('/b', $other);

        $writes = $this->urlMapWritesDuring(fn () => $this->cacher->invalidateUrls([
            $this->domain.'/a',
            $other.'/a',
        ]));

        $this->assertSame(2, $writes);
        $this->assertCachedIs(['/b']);
        $this->assertCachedIs(['/b'], $other);
    }

    #[Test]
    public function it_still_clears_wildcard_urls(): void
    {
        // Wildcards go through the parent's own matching, which resolves them to
        // individual URLs and forgets each one. Those forgets have to land in the
        // same buffer as everything else.
        $this->cache('/blog/one');
        $this->cache('/blog/two');
        $this->cache('/other');

        $writes = $this->urlMapWritesDuring(fn () => $this->cacher->invalidateUrls([
            $this->domain.'/blog/*',
        ]));

        $this->assertSame(1, $writes);
        $this->assertCachedIs(['/other']);
    }

    #[Test]
    public function refreshing_reads_the_url_map_without_writing_it(): void
    {
        Queue::fake();

        $this->cache('/a');
        $this->cache('/b');

        $writes = $this->urlMapWritesDuring(fn () => $this->cacher->refreshUrls([
            $this->domain.'/a',
            $this->domain.'/b',
        ]));

        // A refresh warms pages back up rather than dropping them, so the map is
        // unchanged and must not be written at all.
        $this->assertSame(0, $writes);
        $this->assertCachedIs(['/a', '/b']);
    }

    /**
     * Writes of a URL map, as opposed to the many small per-response keys that
     * a pass legitimately touches. Statamic names them `<hash>.urls`.
     */
    private function urlMapWritesDuring(callable $callback): int
    {
        $writes = 0;

        Event::listen(KeyWritten::class, function (KeyWritten $event) use (&$writes): void {
            if (str_ends_with($event->key, '.urls')) {
                $writes++;
            }
        });

        $callback();

        return $writes;
    }

    private function cache(string $path, ?string $domain = null): void
    {
        $this->cacher->cacheUrl(md5($path), $path, $domain ?? $this->domain);
    }

    /**
     * @param  list<string>  $expected
     */
    private function assertCachedIs(array $expected, ?string $domain = null): void
    {
        $this->assertEqualsCanonicalizing(
            $expected,
            $this->cacher->getUrls($domain ?? $this->domain)->values()->all(),
        );
    }
}
