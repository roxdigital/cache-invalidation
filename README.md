# Rox Digital Cache Invalidation

Statamic addon for targeted static cache invalidation on pagebuilder-driven sites.

## Installation

Install the package:

```bash
composer require roxdigital/cache-invalidation
php artisan vendor:publish --tag=cache-invalidation-config
```

For local development, add a path repository to the host app first:

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

Then configure Statamic to use the addon invalidator:

```php
// config/statamic/static_caching.php
'invalidation' => [
    'class' => RoxDigital\CacheInvalidation\ContentDependencyInvalidator::class,
    'rules' => [],
],
```

## Configuration

Edit `config/cache_invalidation.php` after publishing it.

```php
return [
    'pagebuilder_collections' => ['pages'],

    'globals_flush_all' => ['seo', 'tracking'],
    'navs_flush_all' => ['main_nav'],

    'global_target_blocks' => [
        'reviews' => ['statistics'],
    ],

    'global_urls' => [
        'settings' => ['/'],
    ],

    'collection_entry_rules' => [
        'reusable_blocks' => [
            ['block' => 'reusable_block', 'field' => 'entry'],
        ],
        'projects' => [
            ['block' => 'project_grid', 'field' => 'projects'],
        ],
        'articles' => [
            ['block' => 'article_carousel_block'],
        ],
    ],

    'collection_urls' => [
        'articles' => ['/blog'],
    ],

    'taxonomy_target_blocks' => [
        'tags' => ['article_overview_block'],
    ],

    'taxonomy_urls' => [
        'tags' => ['/blog'],
    ],
];
```

## Rules

- `pagebuilder_collections`: collections scanned for a raw `pagebuilder` field.
- `globals_flush_all`: global handles that flush the whole static cache.
- `navs_flush_all`: navigation handles that flush the whole static cache.
- `global_target_blocks`: global handles that invalidate pages containing listed pagebuilder block types.
- `global_urls`: global handles that invalidate explicit URLs.
- `collection_entry_rules`: collection handles that invalidate matching pagebuilder blocks.
- `collection_urls`: collection handles that invalidate explicit URLs, usually overview pages.
- `taxonomy_target_blocks`: taxonomy handles that invalidate pages containing listed pagebuilder block types.
- `taxonomy_urls`: taxonomy handles that invalidate explicit URLs.

Collection rules can target every page containing a block:

```php
['block' => 'project_grid']
```

Or only pages where a block field references the saved entry:

```php
['block' => 'project_grid', 'field' => 'projects']
```

Use `'collection_handle' => 'all'` only when saving an entry in that collection should invalidate every cached URL.

## Performance

Targeted pagebuilder invalidation uses a cached `url => blocks` index. The index is cleared when the whole cache is flushed, when an entry in `pagebuilder_collections` is saved, or when a `reusable_blocks` entry is saved.

Use a queue driver in production so Statamic invalidation work runs outside the Control Panel save request.
