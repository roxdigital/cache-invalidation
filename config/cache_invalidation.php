<?php

declare(strict_types=1);

return [

    /*
    |--------------------------------------------------------------------------
    | Pagebuilder collections
    |--------------------------------------------------------------------------
    |
    | Only entries from these collections are scanned when determining which
    | cached page URLs to clear. List collections whose entries have a
    | pagebuilder replicator field.
    |
    */

    'pagebuilder_collections' => [
        'pages',
    ],

    /*
    |--------------------------------------------------------------------------
    | Globals that flush the entire static cache
    |--------------------------------------------------------------------------
    |
    | Use this for globals rendered in shared layout or SEO output.
    |
    */

    'globals_flush_all' => [
        'redirects',
    ],

    /*
    |--------------------------------------------------------------------------
    | Navigations that flush the entire static cache
    |--------------------------------------------------------------------------
    */

    'navs_flush_all' => [
        'navigation',
    ],

    /*
    |--------------------------------------------------------------------------
    | Collection trees that flush the entire static cache
    |--------------------------------------------------------------------------
    |
    | Saving a collection tree always clears the block index, because a move
    | changes entry URLs and the index is keyed on them. List a collection here
    | as well when its tree drives shared output — a nav or breadcrumbs built
    | from the page tree, say — since reordering it changes every cached page
    | and no block rule can express that. Empty by default.
    |
    */

    'collection_trees_flush_all' => [
        //
    ],

    /*
    |--------------------------------------------------------------------------
    | Flush the entire static cache when a form blueprint is saved
    |--------------------------------------------------------------------------
    |
    | A form blueprint change alters the fields rendered by every page that
    | embeds that form, and those pages cannot be resolved from the block
    | index, so the whole cache is flushed.
    |
    */

    'forms_flush_all' => true,

    /*
    |--------------------------------------------------------------------------
    | Globals that target pagebuilder block types
    |--------------------------------------------------------------------------
    |
    | Format: 'global_handle' => ['block_type', ...]
    |
    */

    'global_target_blocks' => [
        //
    ],

    /*
    |--------------------------------------------------------------------------
    | Globals that clear explicit URLs
    |--------------------------------------------------------------------------
    |
    | Format: 'global_handle' => ['/url', ...]
    |
    */

    'global_urls' => [
        //
    ],

    /*
    |--------------------------------------------------------------------------
    | Collection entry rules
    |--------------------------------------------------------------------------
    |
    | Rule without field: ['block' => 'block_type']
    | Rule with field: ['block' => 'block_type', 'field' => 'field_handle']
    | Flush all cached URLs for a collection: 'collection' => 'all'
    |
    | The two rules above resolve pages through the block index, so they only
    | reach what a pagebuilder block renders. For relations rendered by a
    | collection's own template there are two more:
    |
    | Parent page: ['parent' => true]
    |     Clears the saved entry's parent page. For a structured collection
    |     whose parent template lists its children.
    |     Opt-in per collection, not automatic: in a collection mounted at the
    |     site root a top-level entry's parent is the root itself, so applying
    |     this everywhere would clear the home page on every save.
    |
    | Referencing entries: ['collection' => 'handle', 'field' => 'field_handle']
    |     Clears the URL of every entry in that collection whose field
    |     references the saved entry — the inverse of a block rule. Use it when
    |     the referencing markup is in a template rather than a block, e.g. an
    |     article detail page rendering its author from the employees
    |     collection.
    |     This walks the named collection on each save of the source
    |     collection, so keep an eye on it for very large collections.
    |
    */

    'collection_entry_rules' => [
        'reusable_blocks' => [
            ['block' => 'reusable_block', 'field' => 'entry'],
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Collections that clear explicit URLs
    |--------------------------------------------------------------------------
    |
    | Format: 'collection_handle' => ['/overview', ...]
    |
    */

    'collection_urls' => [
        //
    ],

    /*
    |--------------------------------------------------------------------------
    | Taxonomies that target pagebuilder block types
    |--------------------------------------------------------------------------
    |
    | Format: 'taxonomy_handle' => ['block_type', ...]
    |
    */

    'taxonomy_target_blocks' => [
        //
    ],

    /*
    |--------------------------------------------------------------------------
    | Taxonomies that clear explicit URLs
    |--------------------------------------------------------------------------
    |
    | Format: 'taxonomy_handle' => ['/overview', ...]
    |
    */

    'taxonomy_urls' => [
        //
    ],

];
