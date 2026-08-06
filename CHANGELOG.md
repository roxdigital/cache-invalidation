# Changelog

All notable changes to this project will be documented in this file.

## [Unreleased]

## [2.0.0] - 2026-08-06

Invalidation is now derived from what pages actually read, instead of from rules
describing what they might read. There is no configuration to write.

The 1.x design kept a hand-maintained map of block types and field handles that
had to mirror the templates. It drifted silently — a URL that no longer resolved
invalidated nothing, and a relation rendered outside the `pagebuilder` field was
invisible to the block index by construction. The template already knows what it
renders; v2 observes it rather than restating it.

### Added

- A `url -> tags` dependency graph, recorded while a page renders and written when
  it enters the static cache. Works identically on the `half` and `full`
  strategies.
- Read recording for entries, terms, globals and forms, hooked at the query
  builder, repository and augmentation level rather than in templates.
- Item tags versus list tags: a query pinned to ids records only those items, while
  any other query also records its collection or taxonomy, so an entry created
  later still invalidates listings that have never seen it.
- Storage drivers: `sqlite` (default, owns its own connection and schema, needs no
  database configured), `database` (opt-in, with a migration), and `null` (records
  nothing, so every save clears everything).
- A safety net: any cached URL absent from the graph is treated as depending on
  everything. Covers pages cached before install, a lost graph, and recorder bugs,
  so the failure mode is over-invalidation that heals after one render rather than
  a page that stays stale with no symptom.
- `cache-invalidation:why`, `:affected`, `:stats` and `:doctor`. `affected` answers
  "what clears if I save this?" before saving — the question the 1.x design could
  not be asked. `doctor` exits non-zero when invalidation cannot work, so a broken
  environment fails a deploy.
- An `X-Cache-Tags` header behind `CACHE_INVALIDATION_DEBUG`.
- A public API for data this addon cannot observe — an HTTP call, a custom Eloquent
  model, a file. `CacheTags::add()` (or `@cachetags(...)`) declares the dependency
  where it is rendered, `CacheTags::invalidate()` clears it wherever that data
  changes, and `cache-invalidation:clear` does the same from the command line.
  Unlike a content save this does not sweep up untracked URLs, so a targeted call
  stays targeted right after a deploy.
- A test suite: 74 tests over Testbench, asserting recording through real queries
  against real content and invalidation through real save events. Mutation-checked,
  and verified to fail against the bugs it covers.

### Changed

- The whole cache is no longer flushed for globals, navigations, form blueprints or
  collection trees. URLs are invalidated individually, so `nocache` regions and the
  graph survive. A navigation save still clears every cached URL — a reorder
  changes links in shared layout and no per-page dependency can express that — but
  it clears rather than flushes.
- Globals invalidate only where they are read. A set rendered in the layout still
  reaches every page; one rendered by a single block reaches that block's pages.
- Form blueprint saves clear the pages rendering that form instead of the entire
  site.
- The addon now claims Statamic's invalidator when the configured class is one of
  its own, not only when the config is null. Sites pin it by name, and a 1.x pin
  would otherwise fatal on a class that no longer exists.

### Fixed

- `Invalidator::refresh()` is honoured. `DefaultInvalidator` flips its `$refreshing`
  flag before delegating to `invalidate()`, which 1.x overrode without checking, so
  `statamic.static_caching.background_recache` hard-purged instead of refreshing.
- Relations rendered outside the `pagebuilder` field — an entry's `author`,
  `category`, a hero fieldset, entry links inside Bard — now invalidate. The 1.x
  block index only read `$entry->get('pagebuilder')`, and the
  `['collection' => …, 'field' => …]` rule existed to patch that hole by walking a
  whole collection on every save.

### Removed

- Every rule key: `pagebuilder_collections`, `collection_entry_rules`,
  `collection_urls`, `globals_flush_all`, `navs_flush_all`,
  `collection_trees_flush_all`, `forms_flush_all`, `global_target_blocks`,
  `global_urls`, `taxonomy_target_blocks`, `taxonomy_urls`.
  `cache-invalidation:doctor` reports any still present in a published config.
- The block index and its supporting classes, along with `customEntryUrls()`. The
  relations that hook existed for are now observed.

## [1.2.0] - 2026-07-29

### Added

Two rule types for `collection_entry_rules`, so dependencies rendered by a
collection's own template no longer need a site-specific invalidator subclass.
Both resolve URLs without the block index, which by design only sees pagebuilder
blocks.

- `['parent' => true]` — clears the saved entry's parent page, for a structured
  collection whose parent template lists its children. Opt-in per collection
  rather than automatic: in a collection mounted at the site root, a top-level
  entry's `parent()` is the root itself, so applying it everywhere would clear
  the home page on every save.
- `['collection' => 'handle', 'field' => 'field_handle']` — clears the URL of
  every entry in that collection whose field references the saved entry. The
  inverse of a block rule; use it for relations rendered by a template, such as
  an article detail page showing its author. Walks the named collection on each
  save of the source collection.

### Fixed

- `blockMatchesAnyRule` no longer reads `$rule['block']` unguarded; rules are
  partitioned by type first. Previously a rule without a `block` key raised an
  undefined-key warning and then silently matched nothing.
- `indexRelevantFields` now only collects `field` from `block` rules. A
  `collection` rule's `field` names a field on an entry, not on a block, so
  including it kept unnecessary keys in the block index.

Backwards compatible: existing rules all carry a `block` key and behave
identically, and both new rule types are inert unless configured.

## [1.1.0] - 2026-07-29

## What's Changed
* fix: close static-cache invalidation gaps by @Bahbv in https://github.com/roxdigital/cache-invalidation/pull/1

## New Contributors
* @Bahbv made their first contribution in https://github.com/roxdigital/cache-invalidation/pull/1

**Full Changelog**: https://github.com/roxdigital/cache-invalidation/compare/v1.0.4...v1.1.0

### Fixed

- The block index cache key is now fingerprinted with `collection_entry_rules` and
  `pagebuilder_collections`. The index only stores the block fields named in those
  rules, so an index built under an older rule set was missing the keys new rules
  match on — adding a field-scoped rule silently matched nothing until something
  else happened to clear the index.
- `customEntryUrls()` now always applies. It previously ran only for collections
  with no rules, which made the documented extension point unreachable for exactly
  the collections that need it: having block rules does not mean those rules cover
  every way a collection's entries surface.
- `EntryReferenceExtractor` now resolves `entry::<id>` (link fieldtype) and
  `statamic://entry::<id>` (Bard hrefs), and no longer stops at a UUID-shaped `id`
  key instead of descending into the rest of the array.
- Saving a collection tree clears the block index. The index is keyed on
  `absoluteUrl()`, and Statamic purges moved URLs straight through the cacher
  without going via the invalidator, so nothing cleared the index after a move; a
  pure reorder dispatches no move event at all.
- The `$rules` contextual binding is now also registered for the class named in
  `statamic.static_caching.invalidation.class`. Contextual bindings are keyed on
  the exact concrete, so pointing the config at a subclass previously threw
  `BindingResolutionException` on the first save unless the host app duplicated
  the binding.

### Added

- `collection_trees_flush_all` — collections whose tree order drives shared output
  (a nav or breadcrumbs built from the page tree) and so must flush the whole cache
  on reorder. Empty by default.

All changes are backwards compatible: no constructor signatures changed, the new
config key defaults to empty, and reference extraction only ever matches more.

## [1.0.4] - 2026-07-28

### Added

- `FlushStaticCacheOnFormBlueprintSaved` — flushes the entire static cache when a form blueprint is saved, so changed form fields are reflected on every page that embeds the form. Toggle with the `forms_flush_all` config option.
- `StaticCacheFlusher` — single place for full-cache flushes, used by the global/nav flush-all rules as well as form blueprint saves.

### Fixed

- Full flushes now go through Statamic's `StaticCache` manager instead of the cacher directly, so they behave exactly like `statamic:static:clear`. Flushing the cacher alone left `nocache::` regions and sessions behind (they are not tracked in the cacher's URL list), and never flushed a dedicated `static_cache` store wholesale. Cached pages — including shared error pages such as the 404 — were already cleared either way; the leak was nocache regions surviving a flush and being restored into freshly rendered pages.

## [1.0.3] - 2026-06-02

### Fixed
- Made gh actions work with node24

## [1.0.2] - 2026-06-02

### Fixed
- Fixed release workflow

## [1.0.1] - 2026-06-02

### Added
- First release

## [1.0.0] - 2026-06-02

### Added

- `ContentDependencyInvalidator` — replaces Statamic's default invalidator with targeted, pagebuilder-aware cache invalidation.
- `PagebuilderDependencyScanner` — builds and maintains a `url → blocks[]` index in the Laravel cache so invalidation decisions require no live database queries.
- `PagebuilderBlockResolver` — resolves a page entry's block list, expanding `reusable_block` references inline with circular-reference protection.
- `EntryReferenceExtractor` — safely extracts Statamic entry UUIDs from any field value shape (string, array, Collection, augmented Entry).
- Config-driven rules for globals, navigations, collection entries, and taxonomy terms.
- Support for block-type targeting, field-level entry reference matching, and explicit URL lists.
- `customEntryUrls()` extension point for site-specific subclasses.
- Slim index storage — only `type` and rule-referenced fields are persisted, discarding rich-text and other large values.
