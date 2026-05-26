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
        'seo',
        'trackers',
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
