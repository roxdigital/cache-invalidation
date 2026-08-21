<?php

declare(strict_types=1);

namespace RoxDigital\CacheInvalidation\Facades;

use Illuminate\Support\Facades\Facade;
use RoxDigital\CacheInvalidation\CacheTags as Manager;

/**
 * @method static void add(string ...$tags)
 * @method static int invalidate(string ...$tags)
 * @method static list<string> urlsFor(string ...$tags)
 *
 * @see Manager
 */
final class CacheTags extends Facade
{
    protected static function getFacadeAccessor(): string
    {
        return Manager::class;
    }
}
