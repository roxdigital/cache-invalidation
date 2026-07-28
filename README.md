# Cache Invalidation

Targeted static and half-measure cache invalidation for Statamic sites built with a pagebuilder.

Instead of flushing the entire cache on every save, this addon builds a block-index of your pages and invalidates only the URLs that reference the changed content — by entry, global, navigation, or taxonomy term.

[![Latest Release](https://img.shields.io/github/v/release/roxdigital/cache-invalidation)](https://github.com/roxdigital/cache-invalidation/releases)
[![PHP](https://img.shields.io/badge/PHP-8.4%2B-blue)](https://www.php.net)
[![Statamic](https://img.shields.io/badge/Statamic-6.x-FF269E)](https://statamic.com)

## Requirements

| Dependency | Version |
|------------|---------|
| PHP | `^8.4` |
| Laravel | `^12.0 \|\| ^13.0` |
| Statamic | `^6.0` |

---

## Installation

### 1. Add the repository

Add the GitHub VCS source to your project's `composer.json`:

```json
{
    "repositories": [
        {
            "type": "vcs",
            "url": "https://github.com/roxdigital/cache-invalidation"
        }
    ]
}
```

### 2. Require and publish

```bash
composer require roxdigital/cache-invalidation
php artisan vendor:publish --tag=cache-invalidation-config
```

### 3. Configure Statamic

In `config/statamic/static_caching.php`, point the invalidation class at the addon:

```php
'invalidation' => [
    'class' => \RoxDigital\CacheInvalidation\ContentDependencyInvalidator::class,
    'rules' => [],
],
```

> **Note:** This addon fully replaces Statamic's rule-based invalidator. Keep `rules` as an empty array.

---

## Local development

Use a Composer path repository to work against a local clone:

```json
{
    "repositories": [
        {
            "type": "path",
            "url": "addons/roxdigital/cache-invalidation"
        }
    ]
}
```

```bash
composer require roxdigital/cache-invalidation:@dev
```

---

## Configuration

Edit `config/cache_invalidation.php` after publishing.

```php
return [

    /*
     | Collections whose entries have a pagebuilder replicator field.
     | Only pages from these collections are indexed.
     */
    'pagebuilder_collections' => ['pages'],

    /*
     | Globals that flush the entire static cache on save.
     | Use for globals rendered in shared layout (nav, SEO, redirects).
     */
    'globals_flush_all' => ['redirects'],

    /*
     | Navigations that flush the entire static cache on save.
     */
    'navs_flush_all' => ['main_nav'],

    /*
     | Flush the entire static cache when a form blueprint is saved, so field
     | changes show up on every page that embeds the form. Set to false to
     | leave the cache untouched on form blueprint saves.
     */
    'forms_flush_all' => true,

    /*
     | Globals that invalidate pages containing specific pagebuilder block types.
     | Format: 'global_handle' => ['block_type', ...]
     */
    'global_target_blocks' => [
        'reviews' => ['statistics_block'],
    ],

    /*
     | Globals that always invalidate explicit URLs.
     | Format: 'global_handle' => ['/url', ...]
     */
    'global_urls' => [
        'settings' => ['/'],
    ],

    /*
     | Rules for collection entries.
     |
     | Use 'all' to invalidate every cached URL when any entry in the
     | collection is saved.
     |
     | Rule without field: invalidate every page containing this block type.
     | Rule with field:    invalidate pages where the field references this entry.
     |
     | Format: 'collection_handle' => [['block' => 'type', 'field' => 'handle'], ...]
     */
    'collection_entry_rules' => [
        'reusable_blocks' => [
            ['block' => 'reusable_block', 'field' => 'entry'],
        ],
        'projects' => [
            ['block' => 'project_grid', 'field' => 'projects'],
        ],
        'articles' => [
            ['block' => 'article_carousel_block'],
        ],
    ],

    /*
     | Collections that always invalidate explicit URLs (e.g. overview pages).
     | Format: 'collection_handle' => ['/url', ...]
     */
    'collection_urls' => [
        'articles' => ['/blog'],
    ],

    /*
     | Taxonomies that invalidate pages containing specific block types.
     | Format: 'taxonomy_handle' => ['block_type', ...]
     */
    'taxonomy_target_blocks' => [
        'tags' => ['article_overview_block'],
    ],

    /*
     | Taxonomies that always invalidate explicit URLs.
     | Format: 'taxonomy_handle' => ['/url', ...]
     */
    'taxonomy_urls' => [
        'tags' => ['/blog'],
    ],

];
```

---

## How it works

When any content is saved, the addon resolves which cached URLs to clear:

| Trigger | Behaviour |
|---------|-----------|
| Global in `globals_flush_all` | Full flush via `StaticCache::flush()` (pages, nocache regions, shared errors) + clear block index |
| Nav in `navs_flush_all` | Full flush via `StaticCache::flush()` (pages, nocache regions, shared errors) + clear block index |
| Form blueprint save (when `forms_flush_all`) | Full flush via `StaticCache::flush()` (pages, nocache regions, shared errors) + clear block index |
| Global with `global_target_blocks` rule | Invalidate pages containing those block types |
| Global with `global_urls` rule | Invalidate the configured URLs |
| Entry in `collection_entry_rules` — `'all'` | Invalidate every currently-cached URL |
| Entry in `collection_entry_rules` — block rules | Invalidate pages that match via the block index |
| Entry in `collection_urls` | Invalidate the configured URLs |
| Entry — own URL | Always included |
| Taxonomy term | Same pattern as globals (block targets + explicit URLs) |

### Block index

The addon maintains a `url → blocks[]` index in your Laravel cache. Each block is stored as a slim record containing only its `type` and the fields referenced in your rules — rich text, images, and other large field values are discarded at build time to keep the index small.

The index is built on first access and stored forever. It is cleared when:

- The full cache is flushed (global/nav flush-all or form blueprint save).
- Any entry in `pagebuilder_collections` is saved (page layout may have changed).
- Any entry whose collection has a `reusable_block` rule is saved (embedded content may have changed).

### Reusable blocks

Blocks of type `reusable_block` are expanded inline when the index is built, so pages that embed a reusable block are correctly invalidated when that block entry is updated.

Circular references are detected and skipped.

### Extending

Override `customEntryUrls()` in a site-specific subclass for collections that cannot be expressed with config-driven rules:

```php
class SiteInvalidator extends \RoxDigital\CacheInvalidation\ContentDependencyInvalidator
{
    protected function customEntryUrls(\Statamic\Entries\Entry $entry): \Illuminate\Support\Collection
    {
        if ($entry->collectionHandle() === 'team') {
            return collect(['/about']);
        }

        return collect();
    }
}
```

Register your subclass in `config/statamic/static_caching.php`:

```php
'invalidation' => [
    'class' => \App\StaticCaching\SiteInvalidator::class,
    'rules' => [],
],
```

---

## Performance

- **Use a queue driver in production.** Statamic dispatches invalidation jobs to the queue. Without a queue driver, invalidation runs synchronously inside the CP save request.
- **Use Redis (or another fast cache driver).** The block index is stored as a single serialised entry. A fast driver reduces index rebuild time.
- The index is built once per cache miss. Subsequent invalidations reuse the cached index with no database queries.

---

## Changelog

See [CHANGELOG.md](CHANGELOG.md).

## License

Proprietary — Rox Digital.
