<?php

declare(strict_types=1);

namespace RoxDigital\CacheInvalidation\Cachers;

use Statamic\StaticCaching\Cachers\ApplicationCacher;

/**
 * Half measure. Registered via StaticCache::extend('application', ...).
 */
final class TrackingApplicationCacher extends ApplicationCacher
{
    use RecordsDependencies;
}
