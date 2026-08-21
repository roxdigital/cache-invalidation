<?php

declare(strict_types=1);

namespace RoxDigital\CacheInvalidation\Cachers;

use Statamic\StaticCaching\Cachers\FileCacher;

/**
 * Full measure. Registered via StaticCache::extend('file', ...).
 */
final class TrackingFileCacher extends FileCacher
{
    use RecordsDependencies;
}
