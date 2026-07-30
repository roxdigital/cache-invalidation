# Cache Invalidation

Targeted static and half-measure cache invalidation for Statamic sites built with a pagebuilder.

Instead of flushing the entire cache on every save, this addon builds a block-index of your pages and invalidates only the URLs that reference the changed content — by entry, global, navigation, or taxonomy term.

[![Latest Release](https://img.shields.io/github/v/release/roxdigital/cache-invalidation)](https://github.com/roxdigital/cache-invalidation/releases)
[![PHP](https://img.shields.io/badge/PHP-8.4%2B-blue)](https://www.php.net)
[![Statamic](https://img.shields.io/badge/Statamic-6.x-FF269E)](https://statamic.com)
[![License: MIT](https://img.shields.io/badge/License-MIT-green)](LICENSE)

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

Nothing to do in most cases: the addon registers itself as the invalidator when `statamic.static_caching.invalidation.class` is unset, which is Statamic's default. Set it explicitly only to point at your own subclass:

```php
'invalidation' => [
    'class' => \App\StaticCaching\SiteInvalidator::class,
    'rules' => [],
],
```

> **Note:** This addon replaces Statamic's rule-based invalidator. Keep `rules` as an empty array.

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

`config/cache_invalidation.php` documents every key inline. In short:

| Key | Effect |
|-----|--------|
| `pagebuilder_collections` | Collections with a pagebuilder field. Only these are indexed. |
| `globals_flush_all` | Global handles that flush the whole cache on save. |
| `navs_flush_all` | Nav handles that flush the whole cache — structure edits and reorders. |
| `collection_trees_flush_all` | Collections whose tree order drives shared output (a nav or breadcrumbs built from the page tree). Empty by default. |
| `forms_flush_all` | `true` by default: a form blueprint save flushes the whole cache, since the changed fields render on every page embedding the form. |
| `global_target_blocks` | `'global' => ['block_type', ...]` — invalidate pages containing those blocks. |
| `global_urls` | `'global' => ['/url', ...]` |
| `collection_entry_rules` | Per-collection entry rules — see below. |
| `collection_urls` | `'collection' => ['/url', ...]` |
| `taxonomy_target_blocks` | `'taxonomy' => ['block_type', ...]` |
| `taxonomy_urls` | `'taxonomy' => ['/url', ...]` |

### Entry rules

Map a collection to `'all'` — invalidate every cached URL on any save — or to a list of rules:

```php
'collection_entry_rules' => [

    // Pages containing this block type.
    'articles' => [['block' => 'article_carousel']],

    // Pages where that block's field references the saved entry.
    'reusable_blocks' => [['block' => 'reusable_block', 'field' => 'entry']],

    // The saved entry's parent page, for a structured collection whose parent
    // template lists its children. Opt-in per collection: in a collection
    // mounted at the site root, a top-level entry's parent is the root itself.
    'vacancies' => [['parent' => true]],

    // Entries in another collection whose field references the saved entry —
    // the inverse of a block rule, for relations rendered by a template (an
    // article showing its author). Walks that collection on every save.
    'employees' => [['collection' => 'articles', 'field' => 'author']],

],
```

The two `block` rules resolve through the block index, so they only reach what a pagebuilder block renders. `parent` and `collection` rules do not use the index, and are inert unless configured.

---

## How it works

On save, the addon resolves which cached URLs to clear:

| Trigger | Behaviour |
|---------|-----------|
| Global in `globals_flush_all` | Full flush + clear block index |
| Nav in `navs_flush_all` | Full flush + clear block index |
| Form blueprint saved, when `forms_flush_all` | Full flush + clear block index |
| Collection tree saved | Clear block index; full flush if the collection is in `collection_trees_flush_all` |
| Global or taxonomy term | Pages matching `*_target_blocks`, plus `*_urls` |
| Entry in a collection mapped to `'all'` | Every currently-cached URL |
| Entry matching `collection_entry_rules` | Block-index matches, parent page, referencing entries |
| Entry — always | Its own URL, `collection_urls`, and `customEntryUrls()` |

A **full flush** goes through `StaticCache::flush()`, the same path as `php artisan statamic:static:clear`: cached pages, `nocache` regions and cached error pages such as a shared 404. Flushing the cacher alone would leave nocache regions behind to be restored into freshly rendered pages.

### Block index

The addon maintains a `url → blocks[]` index in your Laravel cache. Each block is stored as a slim record containing only its `type` and the fields your rules reference — rich text, images and other large values are discarded at build time.

The index is built on first access and stored forever. It is cleared when:

- The full cache is flushed.
- Any collection tree is saved — a move changes the URLs the index is keyed on, and a reorder dispatches no move event at all.
- An entry is saved in a `pagebuilder_collections` collection (page layout may have changed), or in one with a `reusable_block` rule (embedded content may have changed).

Its cache key is fingerprinted with `collection_entry_rules` and `pagebuilder_collections`, so editing either config takes effect immediately instead of matching nothing against an index built under the old rules.

### Reusable blocks

Blocks of type `reusable_block` are expanded inline when the index is built, so pages embedding one are invalidated when that entry changes. Circular references are detected and skipped.

### Extending

Most template-rendered relations are covered by the `parent` and `collection` rules above. For anything they cannot express, override `customEntryUrls()` — it is merged for every entry, whether or not its collection has rules:

```php
class SiteInvalidator extends \RoxDigital\CacheInvalidation\ContentDependencyInvalidator
{
    protected function customEntryUrls(\Statamic\Entries\Entry $entry): \Illuminate\Support\Collection
    {
        return $entry->collectionHandle() === 'team' ? collect(['/about']) : collect();
    }
}
```

Point the config at your subclass; the addon registers its `$rules` binding for whichever class is configured:

```php
'invalidation' => [
    'class' => \App\StaticCaching\SiteInvalidator::class,
    'rules' => [],
],
```

`urlsFor()`, `urlsForGlobal()`, `urlsForEntry()` and `urlsForTaxonomyTerm()` are `protected` if you need to go further.

---

## Performance

- **Use a queue driver in production.** Statamic dispatches invalidation jobs to the queue. Without a queue driver, invalidation runs synchronously inside the CP save request.
- **Use Redis (or another fast cache driver).** The block index is stored as a single serialised entry. A fast driver reduces index rebuild time.
- The index is built once per cache miss. Subsequent invalidations reuse the cached index with no database queries.

---

## Changelog

See [CHANGELOG.md](CHANGELOG.md).

## License

Released under the [MIT License](LICENSE). Copyright © 2026 Rox Digital.

Free to use, modify and distribute, including commercially, provided the copyright
notice is kept intact. Provided **as is**, without warranty of any kind — Rox Digital
accepts no liability. Invalidation decides what your visitors see: verify it against
your own site and caching strategy before relying on it in production.
