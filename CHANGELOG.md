# Changelog

All notable changes to this project will be documented in this file.

## [1.0.4] - 2026-07-28

### Added

- `FlushStaticCacheOnFormBlueprintSaved` — flushes the entire static cache when a form blueprint is saved, so changed form fields are reflected on every page that embeds the form. Toggle with the `forms_flush_all` config option.
- `StaticCacheFlusher` — single place for full-cache flushes, used by the global/nav flush-all rules as well as form blueprint saves.

### Fixed

- Full flushes now go through Statamic's `StaticCache` manager instead of the cacher directly, matching `statamic:static:clear`. This additionally clears nocache regions, the dedicated static cache store and cached error pages, so a cached 404 can no longer survive a flush with a stale nav, footer or globals.

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
