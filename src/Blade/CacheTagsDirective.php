<?php

declare(strict_types=1);

namespace RoxDigital\CacheInvalidation\Blade;

use RoxDigital\CacheInvalidation\CacheTags;

/**
 * Blade sugar over CacheTags::add(), for a dependency this addon cannot observe —
 * typically data reaching the template from outside Statamic's repositories.
 *
 *     @cachetags('api:reviews')
 *
 * Pair it with CacheTags::invalidate('api:reviews') wherever that data changes;
 * nothing else knows when it does.
 */
final class CacheTagsDirective
{
    public static function compile(string $expression): string
    {
        return '<?php app(\\' . CacheTags::class . '::class)->add(' . $expression . '); ?>';
    }
}
