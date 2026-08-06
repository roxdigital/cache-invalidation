# Cache Invalidation

Targeted static cache invalidation for Statamic, with no configuration.

Pages record what they read while they render. Saving content clears exactly the
cached pages that read it — no block index, no rule lists, nothing to maintain
when you add a pagebuilder block.

[![Latest Release](https://img.shields.io/github/v/release/roxdigital/cache-invalidation)](https://github.com/roxdigital/cache-invalidation/releases)
[![PHP](https://img.shields.io/badge/PHP-8.4%2B-blue)](https://www.php.net)
[![Statamic](https://img.shields.io/badge/Statamic-6.x-FF269E)](https://statamic.com)
[![License: MIT](https://img.shields.io/badge/License-MIT-green)](LICENSE)

Requires PHP `^8.4`, Laravel `^12.0 || ^13.0`, Statamic `^6.0`. Works with both the
`half` and `full` static caching strategies.

## Installation

Add the VCS source to your project's `composer.json`:

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

```bash
composer require roxdigital/cache-invalidation
```

That's it. Nothing to publish, no migration, no configuration. The addon registers
itself as Statamic's invalidator and creates its own storage on first use. Verify
with:

```bash
php artisan cache-invalidation:doctor
```

## How it works

While a page renders, the addon watches what it reads and stores a set of tags
against its URL when the page enters the static cache:

```
https://site.test/over-ons
  entry:9f2c…              an entry it rendered
  collection:articles      a query it ran against a collection
  term:departments::sales
  global:footer
  form:contact
```

On save, the changed item is turned into the same tags and every URL carrying one
of them is cleared. The template is the only thing that decides what a page reads,
so there is no second copy of that knowledge to drift out of date.

**Item tags vs list tags.** A query pinned to ids — `Entry::find($id)`, an
`entries` field being augmented — records only those items, so a reusable block on
three pages clears only those three. Any other query also records a list tag for
its scope, because an entry created tomorrow has an id that is in no tag set yet;
`collection:articles` is what clears a "latest three articles" carousel.

**Nesting is free.** A reusable block's reads are the embedding page's reads, at any
depth. If page C embeds a block that pulls in a global and another entry, page C
carries all three dependencies and clears when any of them is saved.

**Where reads are observed:** entries and terms at the query builder, globals at
augmentation (so a global is only tagged where it is actually read), forms at
`FormRepository::find()`.

### What a save clears

| Saved | Cleared |
|---|---|
| Entry | Its own URL and descendants, pages carrying `entry:{id}`, pages carrying its collection's list tag |
| Term | Pages carrying `term:{taxonomy}::{slug}` or `taxonomy:{handle}` |
| Global set | Pages that read it — every page, if it is read in your layout |
| Form or forms blueprint | Pages rendering that form |
| Collection tree | Pages carrying that collection's list tag, plus the URLs Statamic reports as moved |
| Navigation | Every cached URL |

The cache is never flushed wholesale — URLs are invalidated individually, so
`nocache` regions survive and pages come back without a global re-render.

**Safety net.** A URL that is cached but absent from the graph is treated as
depending on everything and cleared by the next save. That covers pages cached
before install and any recorder bug, so the failure mode is over-invalidation that
heals after one render rather than a page that stays stale unnoticed. Right after a
deploy that is every page; it drops to zero as pages render.

## Commands

```bash
# What does this page depend on?
php artisan cache-invalidation:why https://site.test/over-ons

# What would clear if I saved this? Takes an entry id, term id,
# global handle, form handle, or a raw tag.
php artisan cache-invalidation:affected 9f2c1b4e-…
php artisan cache-invalidation:affected collection:articles

# Graph size and coverage of the static cache.
php artisan cache-invalidation:stats

# Clear pages carrying a tag. The counterpart to `affected`:
# preview with that, clear with this.
php artisan cache-invalidation:clear api:reviews

# Deploy check — exits non-zero when invalidation cannot work.
php artisan cache-invalidation:doctor
```

`CACHE_INVALIDATION_DEBUG=true` adds an `X-Cache-Tags` header as pages are cached,
so you can read a page's dependencies in devtools.

## Configuration

Nothing needs setting. Publish it only to change a default:

```bash
php artisan vendor:publish --tag=cache-invalidation-config
```

| Key | Default | Effect |
|-----|---------|--------|
| `driver` | `sqlite` | `sqlite`, `database` or `null` |
| `sqlite_path` | `storage/statamic/cache-invalidation.sqlite` | |
| `database_connection` | `null` | Connection for the `database` driver |
| `debug` | `false` | `X-Cache-Tags` header |

`sqlite` owns its own connection and creates its file and schema on first write, so
it needs no `DB_CONNECTION`. `database` keeps the graph in your app database
instead and needs `php artisan migrate`. `null` records nothing, which clears the
whole cache on every save — a conservative fallback, not a production driver.

> The graph must be visible to every process that renders or invalidates pages. On
> a single server that is automatic; if web and queue run on separate filesystems,
> use `database`.

## Data from outside Statamic

Entries, terms, globals and forms are observed automatically. Data that reaches a
template from somewhere else — an HTTP call, a custom Eloquent model, a file — is
not: nothing sees the read, and nothing knows when it changes. Declare both halves.

Where it is rendered:

```blade
@cachetags('api:reviews')
```

Or from PHP, in a ViewModel or component:

```php
use RoxDigital\CacheInvalidation\Facades\CacheTags;

CacheTags::add('api:reviews');
```

And wherever that data changes — the job that refetched it, say:

```php
CacheTags::invalidate('api:reviews');   // returns the number of URLs cleared
CacheTags::urlsFor('api:reviews');      // preview, without clearing
```

Tags are arbitrary strings; namespace them (`api:reviews`, not `reviews`) so they
cannot collide with a built-in. `CacheTags::invalidate()` also works with built-in
tags, so `CacheTags::invalidate('collection:articles')` clears every page listing
articles.

Unlike a content save, this does not sweep up cached URLs that are missing from the
graph. That safety net exists so routine editing can never leave a page stale;
applying it here would make a targeted call clear everything right after a deploy.

## Upgrading from 1.x

Delete the rule keys from `config/cache_invalidation.php` — all of them are gone.
`cache-invalidation:doctor` lists any still present. A
`statamic.static_caching.invalidation.class` pointing at
`ContentDependencyInvalidator` can stay: the addon recognises its own class names
and upgrades the pin. Your own subclass is still respected, but
`customEntryUrls()` is gone — the relations it existed for are now observed.

Expect one round of broad invalidation after deploying while the graph fills.

## Local development

To work on the addon against a real site, clone it inside the site and point
Composer at the clone instead of the VCS source. Path repositories symlink by
default, so edits in `src/` take effect on the next request with no reinstall.

```bash
git clone git@github.com:roxdigital/cache-invalidation.git addons/roxdigital/cache-invalidation
```

```json
{
    "repositories": [
        {
            "type": "path",
            "url": "addons/roxdigital/cache-invalidation",
            "options": { "symlink": true }
        }
    ]
}
```

```bash
composer require roxdigital/cache-invalidation:@dev
```

Adding a class in a new subdirectory needs `composer dump-autoload` if the site was
installed with an optimised autoloader. Switch back with
`composer require roxdigital/cache-invalidation:^2.0` once the `path` repository is
removed.

Run the addon's own suite from its directory:

```bash
composer install && composer test
```

## Good to know

- Assets are not tracked; saving one clears nothing extra.
- On full measure, run `statamic:static:warm` after a deploy so the graph fills
  promptly instead of lazily.
- Recording only happens on a cache miss. Invalidation is one indexed lookup plus
  the deletes — nothing walks content, which matters with a single queue worker or
  `QUEUE_CONNECTION=sync`.
- A page with more than 2,000 dependencies is treated as depending on everything.

## License

Released under the [MIT License](LICENSE). Copyright © 2026 Rox Digital. Provided
**as is**, without warranty — invalidation decides what your visitors see, so
verify it against your own site before relying on it in production.

See [CHANGELOG.md](CHANGELOG.md) for release notes.
