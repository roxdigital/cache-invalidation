# Cache Invalidation

Targeted static cache invalidation for Statamic, with no configuration.

Pages record what they read while they render. Saving content clears exactly the
cached pages that read it — no block index, no rule lists, nothing to keep in sync
when you add a pagebuilder block.

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

Works with both the `half` and `full` static caching strategies.

---

## Installation

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

```bash
composer require roxdigital/cache-invalidation
```

That's the whole installation. There is nothing to publish, no migration to run,
and no configuration to write. The addon registers itself as Statamic's
invalidator and creates its own storage on first use.

Verify with:

```bash
php artisan cache-invalidation:doctor
```

---

## How it works

### Dependencies are observed, not declared

While a page renders, the addon watches what it reads and records a set of tags
against the page's URL when it enters the static cache:

```
https://site.test/over-ons
  entry:9f2c…            an entry it rendered
  collection:articles    a query it ran against a collection
  term:departments::sales
  taxonomy:departments
  global:footer
  form:contact
```

On save, the changed item is turned into the same tags, and every URL carrying one
of them is cleared. Because the template is the only thing that decides what a
page reads, there is no second copy of that knowledge to drift out of date.

### Item tags and list tags

The distinction that makes this targeted rather than blunt:

- A query pinned to ids — `Entry::find($id)`, an `entries` field being augmented —
  records **item tags** only. A reusable block embedded on three pages clears only
  those three.
- Any other query also records a **list tag** for its scope. A carousel showing
  "the latest three articles" has never seen an article created tomorrow, so its
  id can be in no tag set; the `collection:articles` tag is what clears it.

A block that picks between those modes at runtime — automatic, by author, manual —
gets the right answer for whichever branch actually ran.

### Where reads are observed

| Content | Hook |
|---|---|
| Entries | `EntryQueryBuilder::getFilteredKeys()` and `getItems()` |
| Terms | `TermQueryBuilder`, via a replaced `TermRepository::query()` |
| Globals | `Variables::newAugmentedInstance()`, recorded on first value read |
| Forms | `FormRepository::find()`, which is how the form fieldtype augments |

Globals are recorded lazily, on read. Statamic hydrates every global set into
every view whether a template uses it or not, so a set rendered in your layout
ends up on every page while a set rendered by one block ends up only on that
block's pages.

### What happens on save

| Saved | Cleared |
|---|---|
| Entry | Its own URL and descendants, pages carrying `entry:{id}`, pages carrying `collection:{handle}` |
| Term | Pages carrying `term:{taxonomy}::{slug}` or `taxonomy:{handle}` |
| Global set | Pages that read it — which is every page, if it is read in your layout |
| Form, or a forms blueprint | Pages that render that form |
| Collection tree | Pages carrying that collection's list tag, plus the URLs Statamic reports as moved |
| Navigation | Every cached URL |
| Anything | Plus any cached URL with no recorded dependencies (see below) |

The cache is never flushed wholesale — URLs are invalidated individually, so
`nocache` regions and the graph survive and pages come back without a global
re-render.

A navigation save is the one deliberate exception, per the shape of the problem: a
reorder or relabel changes links rendered in shared layout, and no per-page
dependency can express that.

### The safety net

Any URL that is cached but absent from the graph is treated as depending on
everything, and cleared by the next save of anything. This covers pages cached
before the addon was installed, a lost graph, and any bug in the recorders — the
failure mode is over-invalidation that heals after one render, rather than a page
that stays stale with no symptom.

`cache-invalidation:stats` reports how many such URLs exist. Right after a deploy
or a flush that number is everything; it drops to zero as pages are rendered.

---

## Commands

```bash
# What does this page depend on?
php artisan cache-invalidation:why https://site.test/over-ons

# What would clear if I saved this? Accepts an entry id, term id,
# global set handle, form handle, or a raw tag.
php artisan cache-invalidation:affected 9f2c1b4e-…
php artisan cache-invalidation:affected collection:articles

# Graph size and coverage of the static cache.
php artisan cache-invalidation:stats

# Deploy check. Exits non-zero when invalidation cannot work.
php artisan cache-invalidation:doctor
```

Set `CACHE_INVALIDATION_DEBUG=true` to add an `X-Cache-Tags` header to responses
as they are cached, so a page's dependencies are readable in devtools.

---

## Configuration

There is nothing you need to set. The file exists for two choices:

| Key | Default | Effect |
|-----|---------|--------|
| `driver` | `sqlite` | Where the graph lives — `sqlite`, `database` or `null` |
| `sqlite_path` | `storage/statamic/cache-invalidation.sqlite` | |
| `database_connection` | `null` | Connection for the `database` driver |
| `debug` | `false` | `X-Cache-Tags` header |

```bash
php artisan vendor:publish --tag=cache-invalidation-config
```

**`sqlite`** owns its own connection and creates the file and schema on first
write, so it works on a site with no `DB_CONNECTION` configured — the common
Statamic case. It sits beside Statamic's own static cache bookkeeping so the graph
and the cache share a directory and a deploy that discards one discards both.

**`database`** keeps the graph in your application database instead. Requires
`php artisan migrate`.

**`null`** records nothing, which makes every cached URL untracked and therefore
clears the whole cache on every save. A conservative fallback, not a production
driver.

> The graph must be visible to every process that renders or invalidates pages. On
> a single server that is automatic. If web and queue run on separate machines with
> separate filesystems, use the `database` driver — and note that a file-backed
> static cache would already be inconsistent in that setup.

---

## Escape hatch

One directive, for the only thing observation cannot see: a dependency a template
reacts to without reading.

```blade
{{-- A "we're hiring" banner that queries no vacancies --}}
@cachetags('collection:vacancies')
```

Everything a template actually reads is recorded on its own. This is for the
exception.

---

## Upgrading from 1.x

Remove the rule keys from `config/cache_invalidation.php` — all of
`pagebuilder_collections`, `collection_entry_rules`, `collection_urls`,
`globals_flush_all`, `navs_flush_all`, `collection_trees_flush_all`,
`forms_flush_all`, `global_target_blocks`, `global_urls`,
`taxonomy_target_blocks` and `taxonomy_urls` are gone. `cache-invalidation:doctor`
lists any that are still present.

If `statamic.static_caching.invalidation.class` points at
`ContentDependencyInvalidator`, you can leave it — the addon recognises its own
class names and upgrades the pin. A subclass of your own is still respected, but
`customEntryUrls()` no longer exists; the relations it existed for are now
observed automatically.

After deploying, expect one round of broad invalidation while pages are rendered
and the graph fills. `cache-invalidation:stats` shows the progress.

---

## Notes

- **Assets are not tracked.** Saving an asset clears nothing extra, matching 1.x.
- **Cold pages record nothing**, which is correct — there is nothing cached to
  clear. On full measure, run `statamic:static:warm` after a deploy so the graph
  fills promptly rather than lazily.
- **Recording only happens on a cache miss**, during a render you are already
  paying for. Invalidation is one indexed lookup plus the deletes; nothing walks
  content, which matters when a single queue worker handles the job — or when
  `QUEUE_CONNECTION=sync` runs it inside the editor's save request.
- **A page with more than 2,000 dependencies** collapses to a single overflow tag
  and is treated as depending on everything.

---

## Changelog

See [CHANGELOG.md](CHANGELOG.md).

## License

Released under the [MIT License](LICENSE). Copyright © 2026 Rox Digital.

Free to use, modify and distribute, including commercially, provided the copyright
notice is kept intact. Provided **as is**, without warranty of any kind — Rox Digital
accepts no liability. Invalidation decides what your visitors see: verify it against
your own site and caching strategy before relying on it in production.
