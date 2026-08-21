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

Recording extends Statamic's Stache repositories and query builders, so this assumes
the standard flat-file content driver. A site running `statamic/eloquent-driver`
replaces those, and has not been tested.

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

### How reads are observed

Every piece of content in Statamic is fetched through a repository or a query
builder resolved from the container. The addon replaces those with subclasses, so
each read passes through it on the way to your template — nothing in your code
changes, and there is no scanning or parsing.

| Content | Where it is recorded |
|---|---|
| Entries | `EntryQueryBuilder::getFilteredKeys()` and `getItems()` |
| Terms | `TermQueryBuilder`, via a replaced `TermRepository::query()` |
| Globals | `Variables::newAugmentedInstance()`, on first value read |
| Forms | `FormRepository::find()` |
| Navigations | `NavigationRepository`, `NavTreeRepository`, and the `nav` tag |

Entries hook `getFilteredKeys()` rather than `get()` because `count()` and
`pluck()` bypass `get()` entirely; `getItems()` then adds item tags for the entries
that survived `limit` and `offset`. A request-scoped recorder collects the tags, and
the cacher writes them against the URL as the page is stored.

Because the hooks sit at that level, how a template asks does not matter — a PHP
query in a `@php` block or view model, an Antlers tag (`<s:collection>`,
`<s:taxonomy>`, `<s:nav>`, `<s:form>`), an augmented field (`$block->entry`), or a
global off the cascade (`$footer->phone`) all pass through. What does not is content
fetched outside Statamic entirely; see [Data from outside Statamic](#data-from-outside-statamic).

This only runs on a cache miss — during a render you are already paying for. A
cached hit never reaches it.

### What a save clears

| Saved | Cleared |
|---|---|
| Entry | Its own URL and descendants, pages carrying `entry:{id}`, pages carrying its collection's list tag |
| Term | Pages carrying `term:{taxonomy}::{slug}` or `taxonomy:{handle}` |
| Global set | Pages that read it — every page, if it is read in your layout |
| Form or forms blueprint | Pages rendering that form |
| Collection tree | Pages carrying that collection's list tag, plus the URLs Statamic reports as moved |
| Navigation | Pages that render it — every page, if it is in your layout |

The cache is never flushed wholesale — URLs are invalidated individually, so
`nocache` regions survive and pages come back without a global re-render. Nothing
is special-cased either: a navigation or global that reaches every page does so
because it is recorded on every page, not because of a rule.

**Rendered output only.** Two kinds of read are deliberately excluded, because
recording them makes almost every save clear almost everything:

- **URL resolution.** Statamic reads content to work out which entry a URL belongs
  to, and for a structured collection that includes validating the whole collection
  tree. The page does not display that.
- **Navigation menus.** A nav is recorded as `nav:{handle}`, not as an `entry:` tag
  per menu item. Otherwise a menu in your layout would make every page depend on
  every page in it, and renaming one would clear the site.

The second is a deliberate trade-off: rename a page and its menu label stays stale on
already-cached pages until they clear for another reason. A page save clears where
that page is rendered *as content*; the menu is the navigation's concern, so **save
the navigation to update it everywhere**. That is the intended workflow — changing a
menu means editing the navigation.

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

# Run after upgrading Statamic: verifies the addon still fits this version.
php artisan cache-invalidation:selftest
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

Declare it where it renders — `@cachetags('api:reviews')` in Blade, or
`CacheTags::add('api:reviews')` from a ViewModel or component — and clear it wherever
that data changes:

```php
use RoxDigital\CacheInvalidation\Facades\CacheTags;

CacheTags::invalidate('api:reviews');   // returns the number of URLs cleared
CacheTags::urlsFor('api:reviews');      // preview, without clearing
```

Tags are arbitrary strings; namespace them (`api:reviews`, not `reviews`) so they
cannot collide with a built-in. It works with built-in tags too, so
`CacheTags::invalidate('collection:articles')` clears every page listing articles.

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

## Upgrading Statamic

Recording works by subclassing Statamic internals, several of which are `protected`
and not public API. A Statamic upgrade can move them, so **run this addon's test
suite after bumping `statamic/cms`** — it is built to fail loudly on exactly these
seams rather than degrade quietly.

**From inside the site**, right after bumping `statamic/cms`:

```bash
php artisan cache-invalidation:selftest
```

It checks every seam twice against the Statamic version that site has installed:
structurally, that the methods and properties still exist; and behaviourally, by
recording against your own content and asserting the tags that come back. The second
kind is what matters — a method can survive a rename and still behave differently,
which is how every bug found while building this actually presented. Exits non-zero
on failure, so it can gate a Statamic upgrade in CI.

Checks that need content the site does not have are skipped, not failed.

The addon's own suite is the fuller check, but it can only run from a clone — its dev
dependencies are never installed in a site:

```bash
git clone git@github.com:roxdigital/cache-invalidation.git && cd cache-invalidation
composer install && composer test
```

What it extends, and the member that matters:

| Statamic class | Relied on |
|---|---|
| `Stache\Query\EntryQueryBuilder` | `getFilteredKeys()`, `getItems()`, `$collections`, `$wheres` |
| `Stache\Query\TermQueryBuilder` | `getFilteredKeys()`, `getItems()`, `$taxonomies` |
| `Stache\Repositories\EntryRepository` | `findByUri()` |
| `Stache\Repositories\TermRepository` | `query()`, `ensureAssociations()`, `$store` |
| `Stache\Repositories\NavigationRepository` | `findByHandle()`, `all()` |
| `Stache\Repositories\NavTreeRepository` | `find()` |
| `Globals\Variables` + `AugmentedVariables` | `newAugmentedInstance()`, `get()` |
| `Forms\FormRepository` | `find()` |
| `Tags\Nav` | `structure()` |
| `StaticCaching\Cachers\ApplicationCacher`, `FileCacher` | `cachePage()`, `getUrl()`, `isExcluded()` |
| `StaticCaching\DefaultInvalidator` | `getItemUrls()`, `$refreshing`, `$cacher` |
| `StaticCaching\StaticCacheManager` | `extend()`, and that custom creators receive the merged strategy config |

Three behavioural assumptions are worth naming, because a change there breaks
nothing visibly:

- **The `$wheres` array shape** (`type`, `column`, `operator`, `boolean`) decides
  whether a query counts as a lookup of specific items. A change here silently makes
  invalidation broader or — worse — narrower.
- **Tag classes resolve through the container** (`Tags\Loader::init`), which is how
  the nav tag is replaced without touching the tag registry.
- **`StaticCaching\Invalidate` is `ShouldQueue`** and drives invalidation from a
  fixed list of content events. Anything not on that list clears nothing; see
  [Not covered](#not-covered).

## Local development

Clone the addon inside a site and point Composer at the clone. Path repositories
symlink by default, so edits in `src/` take effect on the next request.

```bash
git clone git@github.com:roxdigital/cache-invalidation.git addons/roxdigital/cache-invalidation
```

```json
{
    "repositories": [
        { "type": "path", "url": "addons/roxdigital/cache-invalidation" }
    ]
}
```

```bash
composer require roxdigital/cache-invalidation:@dev
```

A class in a new subdirectory needs `composer dump-autoload` if the site uses an
optimised autoloader. To test a branch as a consumer would get it, require the
branch alias instead — `2.x-dev` for branch `v2`, not `dev-v2`.

The addon's own suite runs from its directory with `composer install && composer test`.

## Deploying

- **Clear the cache when you deploy code.** Templates, translations and PHP are not
  content, so nothing dispatches an event for them — change a Blade file and the
  cache keeps serving the old markup. `php artisan statamic:static:clear` belongs in
  your deploy script; this addon narrows content invalidation, not code deploys.
- Run `cache-invalidation:doctor` as a deploy step. It exits non-zero when
  invalidation cannot work, so a broken environment fails the pipeline instead of
  quietly serving stale pages.
- Restart your queue workers (`php artisan queue:restart`). A worker holds an open
  handle on the graph, and a deploy that replaces `storage/` leaves it writing to a
  file that no longer exists. The safety net turns that into over-invalidation
  rather than staleness, but a restart avoids it.
- Expect one broad invalidation after deploying. Every cached URL is untracked
  until it has been rendered again, so the first save clears more than usual.
  `cache-invalidation:stats` shows the graph filling up.
- On full measure, `statamic:static:warm` fills the graph promptly instead of
  lazily.

## Not covered

Statamic only dispatches invalidation events for content, and only some of it, so a
few changes clear nothing. None of these are silent in a surprising way — they are
listed so you know where the edges are.

- **Assets.** Replacing an image or setting a focal point in the asset browser clears
  nothing on its own. `AssetSaved` reaches the invalidator, but asset reads are not
  recorded, so there is no tag to match. In practice this rarely bites: assets are
  normally changed while working on a page, and saving that page clears it. Save the
  page if you edited an asset in isolation.
- **Blueprints and fieldsets outside forms.** Adding a field to a collection
  blueprint can change every page of that collection; Statamic only routes the
  `forms` namespace to invalidation.
- **Users.** A user save dispatches nothing. If a template renders a user's name,
  rename them and it stays. Authors kept as entries are unaffected.
- **Templates, translations and code.** See Deploying above.

## Good to know

- Invalidation is one indexed lookup plus the deletes. Nothing walks content, which
  matters with a single queue worker or `QUEUE_CONNECTION=sync`, where it runs inside
  the editor's save request.
- A page with more than 2,000 dependencies is treated as depending on everything.
- Globals are not scoped per site, so on a multisite install saving one clears the
  pages that read it across every site.

## License

Released under the [MIT License](LICENSE). Copyright © 2026 Rox Digital. Provided
**as is**, without warranty — invalidation decides what your visitors see, so
verify it against your own site before relying on it in production.

See [CHANGELOG.md](CHANGELOG.md) for release notes.
