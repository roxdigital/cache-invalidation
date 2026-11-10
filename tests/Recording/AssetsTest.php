<?php

declare(strict_types=1);

namespace RoxDigital\CacheInvalidation\Tests\Recording;

use Illuminate\Support\Facades\Storage;
use PHPUnit\Framework\Attributes\Test;
use RoxDigital\CacheInvalidation\Tests\TestCase;
use Statamic\Facades\Asset;
use Statamic\Facades\AssetContainer;
use Statamic\Facades\Collection;
use Statamic\Facades\Entry;

/**
 * Assets are read three ways while a page renders: the assets fieldtype queries
 * its container by path, Bard and the glide tag resolve an id or URL through the
 * repository, and a template can list a container. Each is exercised against
 * real files, for the same reason ItemAndListTagsTest avoids synthetic queries.
 */
final class AssetsTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('test', ['url' => '/assets']);
        Storage::disk('test')->put('one.txt', 'one');
        Storage::disk('test')->put('two.txt', 'two');

        AssetContainer::make('test')->disk('test')->save();
    }

    #[Test]
    public function an_augmented_assets_field_records_only_the_asset_it_shows(): void
    {
        // The focal point case: an image field on a page. Augmentation queries the
        // container with whereIn('path', ...), which must count as an item lookup.
        $this->blueprint('collections.pages', 'page', [
            'image' => ['type' => 'assets', 'container' => 'test', 'max_files' => 1],
        ]);

        Collection::make('pages')->save();

        $page = tap(Entry::make()->collection('pages')->slug('home')
            ->data(['title' => 'Home', 'image' => 'one.txt']))->save();

        $tags = $this->tagsRecordedDuring(fn () => $page->augmentedValue('image')->value());

        $this->assertContains('asset:test::one.txt', $tags);
        $this->assertNotContains('asset:test::two.txt', $tags);
        $this->assertNotContains('asset-container:test', $tags, 'a path-pinned lookup must not record a list tag');
    }

    #[Test]
    public function an_augmented_multi_assets_field_records_each_asset(): void
    {
        $this->blueprint('collections.pages', 'page', [
            'gallery' => ['type' => 'assets', 'container' => 'test'],
        ]);

        Collection::make('pages')->save();

        $page = tap(Entry::make()->collection('pages')->slug('home')
            ->data(['title' => 'Home', 'gallery' => ['one.txt', 'two.txt']]))->save();

        $tags = $this->tagsRecordedDuring(fn () => $page->augmentedValue('gallery')->value()->get());

        $this->assertContains('asset:test::one.txt', $tags);
        $this->assertContains('asset:test::two.txt', $tags);
        $this->assertNotContains('asset-container:test', $tags);
    }

    #[Test]
    public function finding_an_asset_by_id_records_only_that_asset(): void
    {
        // How Bard resolves an inline image, and how the glide tag resolves a
        // string src.
        $tags = $this->tagsRecordedDuring(fn () => Asset::find('test::one.txt'));

        $this->assertSame(['asset:test::one.txt'], $tags);
    }

    #[Test]
    public function finding_an_asset_by_url_records_it(): void
    {
        $tags = $this->tagsRecordedDuring(fn () => Asset::findByUrl('/assets/two.txt'));

        $this->assertSame(['asset:test::two.txt'], $tags);
    }

    #[Test]
    public function listing_a_container_records_the_list_tag_and_every_asset(): void
    {
        $tags = $this->tagsRecordedDuring(fn () => AssetContainer::find('test')->queryAssets()->get());

        $this->assertContains('asset-container:test', $tags);
        $this->assertContains('asset:test::one.txt', $tags);
        $this->assertContains('asset:test::two.txt', $tags);
    }

    #[Test]
    public function counting_a_container_records_the_list_tag(): void
    {
        // count() never reaches getItems(), so this only passes because the list
        // tag is recorded in getFilteredKeys().
        $tags = $this->tagsRecordedDuring(fn () => AssetContainer::find('test')->queryAssets()->count());

        $this->assertSame(['asset-container:test'], $tags);
    }

    #[Test]
    public function a_missing_asset_records_nothing(): void
    {
        $this->assertSame([], $this->tagsRecordedDuring(fn () => Asset::find('test::nope.txt')));
    }
}
