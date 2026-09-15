<?php

declare(strict_types=1);

namespace RoxDigital\CacheInvalidation\Tests;

use Illuminate\Support\Facades\Blade;
use PHPUnit\Framework\Attributes\Test;
use RoxDigital\CacheInvalidation\Facades\CacheTags;
use RoxDigital\CacheInvalidation\Tag;
use Statamic\StaticCaching\Cacher;

final class CacheTagsTest extends TestCase
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
    public function it_records_a_custom_tag_against_the_current_render(): void
    {
        CacheTags::add('api:reviews');

        $this->assertSame(['api:reviews'], $this->recorder->tags());
    }

    #[Test]
    public function the_blade_directive_records_through_the_same_api(): void
    {
        $compiled = Blade::compileString("@cachetags('api:reviews', 'api:ratings')");

        eval('?>'.$compiled);

        $this->assertSame(['api:reviews', 'api:ratings'], $this->recorder->tags());
    }

    #[Test]
    public function it_clears_cached_pages_carrying_a_custom_tag(): void
    {
        $this->cache('/reviews', ['api:reviews']);
        $this->cache('/ratings', ['api:ratings']);

        $cleared = CacheTags::invalidate('api:reviews');

        $this->assertSame(1, $cleared);
        $this->assertCachedIs(['/ratings']);
    }

    #[Test]
    public function it_accepts_several_tags_at_once_and_counts_each_url_once(): void
    {
        $this->cache('/both', ['api:reviews', 'api:ratings']);
        $this->cache('/neither', ['api:other']);

        $this->assertSame(1, CacheTags::invalidate('api:reviews', 'api:ratings'));
        $this->assertCachedIs(['/neither']);
    }

    #[Test]
    public function it_also_clears_pages_that_depend_on_everything(): void
    {
        $this->cache('/overflowed', [Tag::OVERFLOW]);
        $this->cache('/unrelated', ['api:other']);

        CacheTags::invalidate('api:reviews');

        $this->assertCachedIs(['/unrelated']);
    }

    #[Test]
    public function it_works_with_built_in_tags_too(): void
    {
        $this->cache('/overview', ['collection:articles']);
        $this->cache('/detail', ['entry:abc']);

        CacheTags::invalidate('collection:articles');

        $this->assertCachedIs(['/detail']);
    }

    #[Test]
    public function it_leaves_untracked_urls_alone(): void
    {
        // Deliberate divergence from a content save. The untracked sweep exists so
        // routine editing can never leave a page stale; folding it in here would
        // make an explicit, targeted call clear everything right after a deploy.
        $this->cache('/reviews', ['api:reviews']);
        $this->cacher->cacheUrl(md5('/untracked'), '/untracked', $this->domain);

        CacheTags::invalidate('api:reviews');

        $this->assertCachedIs(['/untracked']);
    }

    #[Test]
    public function invalidating_an_unknown_tag_clears_nothing(): void
    {
        $this->cache('/reviews', ['api:reviews']);

        $this->assertSame(0, CacheTags::invalidate('api:nothing'));
        $this->assertCachedIs(['/reviews']);
    }

    #[Test]
    public function invalidating_no_tags_clears_nothing(): void
    {
        // Guards against an empty argument list matching the overflow tag and
        // taking the whole cache with it.
        $this->cache('/overflowed', [Tag::OVERFLOW]);

        $this->assertSame(0, CacheTags::invalidate());
        $this->assertSame(0, CacheTags::invalidate(''));
        $this->assertCachedIs(['/overflowed']);
    }

    #[Test]
    public function it_refreshes_rather_than_purges_when_background_recache_is_on(): void
    {
        config(['statamic.static_caching.background_recache' => true]);

        $cacher = \Mockery::mock(Cacher::class);
        $cacher->shouldReceive('refreshUrls')->once();
        $cacher->shouldNotReceive('invalidateUrls');

        $tags = new \RoxDigital\CacheInvalidation\CacheTags($this->recorder, $this->graph, $cacher);
        $this->graph->record($this->domain.'/reviews', ['api:reviews']);

        $this->assertSame(1, $tags->invalidate('api:reviews'));
    }

    #[Test]
    public function urls_for_reports_without_clearing(): void
    {
        $this->cache('/reviews', ['api:reviews']);

        $this->assertSame([$this->domain.'/reviews'], CacheTags::urlsFor('api:reviews'));
        $this->assertCachedIs(['/reviews']);
    }

    #[Test]
    public function the_clear_command_reports_what_it_cleared(): void
    {
        $this->cache('/reviews', ['api:reviews']);

        $this->artisan('cache-invalidation:clear', ['tags' => ['api:reviews']])
            ->expectsOutputToContain($this->domain.'/reviews')
            ->expectsOutputToContain('Cleared 1 cached URL(s).')
            ->assertSuccessful();

        $this->assertCachedIs([]);
    }

    #[Test]
    public function the_clear_command_says_so_when_nothing_matches(): void
    {
        $this->artisan('cache-invalidation:clear', ['tags' => ['api:nothing']])
            ->expectsOutputToContain('No cached page carries api:nothing.')
            ->assertSuccessful();
    }

    /**
     * @param  list<string>  $tags
     */
    private function cache(string $path, array $tags): void
    {
        $this->cacher->cacheUrl(md5($path), $path, $this->domain);
        $this->graph->record($this->domain.$path, $tags);
    }

    /**
     * @param  list<string>  $expected
     */
    private function assertCachedIs(array $expected): void
    {
        $this->assertEqualsCanonicalizing(
            $expected,
            $this->cacher->getUrls($this->domain)->values()->all(),
        );
    }
}
