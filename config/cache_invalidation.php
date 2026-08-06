<?php

declare(strict_types=1);

return [

    /*
    |--------------------------------------------------------------------------
    | Dependency graph driver
    |--------------------------------------------------------------------------
    |
    | Invalidation is driven by a url -> tags graph recorded while pages render.
    | The graph has to share a lifetime with the static cache and be visible to
    | every process that writes or clears it. If the cache outlives the graph,
    | lookups stop matching and pages go stale; if web and worker processes see
    | different copies, invalidation clears nothing.
    |
    | Supported drivers:
    |
    | "sqlite"   Default. Needs nothing from the host app: the addon registers
    |            its own connection and creates the file on first write, so it
    |            works on sites with no DB_CONNECTION configured. Correct for
    |            single-server deploys, which is where a file-backed static
    |            cache works in the first place.
    |
    | "database" Uses the application's database. Pick this when the app already
    |            has one and you would rather keep the graph there. Requires
    |            `php artisan migrate`.
    |
    | "null"     Records nothing. Every invalidation then falls back to clearing
    |            any cached URL the graph does not know about, which means the
    |            whole cache. Useful to rule the addon out while debugging.
    |
    */

    'driver' => env('CACHE_INVALIDATION_DRIVER', 'sqlite'),

    /*
    |--------------------------------------------------------------------------
    | Graph-driven invalidation
    |--------------------------------------------------------------------------
    |
    | Resolve what to clear from the recorded graph rather than from the rule
    | configuration below. Off by default during the transition, so the graph can
    | be recorded and inspected with `cache-invalidation:stats` and
    | `cache-invalidation:affected` before it decides anything.
    |
    */

    'graph' => env('CACHE_INVALIDATION_GRAPH', false),

    /*
    |--------------------------------------------------------------------------
    | Sqlite driver path
    |--------------------------------------------------------------------------
    |
    | Kept next to Statamic's own static cache bookkeeping so the graph and the
    | cache share a directory, and a deploy that discards one discards both.
    |
    */

    'sqlite_path' => storage_path('statamic/cache-invalidation.sqlite'),

    /*
    |--------------------------------------------------------------------------
    | Database driver connection
    |--------------------------------------------------------------------------
    |
    | Null uses the application's default connection.
    |
    */

    'database_connection' => null,

    /*
    |--------------------------------------------------------------------------
    | Debug
    |--------------------------------------------------------------------------
    |
    | Adds an X-Cache-Tags header to responses that are about to be cached, so
    | you can read a page's recorded dependencies in devtools. Cached hits do
    | not carry the header — the render that produced the cache entry does.
    |
    */

    'debug' => env('CACHE_INVALIDATION_DEBUG', false),

];
