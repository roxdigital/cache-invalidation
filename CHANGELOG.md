# Changelog

All notable changes to this project will be documented in this file.

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

## v1.1.0

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
