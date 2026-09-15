<?php

declare(strict_types=1);

namespace RoxDigital\CacheInvalidation\Tests\Doubles;

use Statamic\StaticCaching\DefaultInvalidator;

/**
 * Stands in for a host app's own invalidator, which the addon must leave alone.
 */
final class HostInvalidator extends DefaultInvalidator
{
}
