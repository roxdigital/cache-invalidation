<?php

declare(strict_types=1);

namespace RoxDigital\CacheInvalidation\Blade;

use RoxDigital\CacheInvalidation\Recording\DependencyRecorder;

/**
 * The escape hatch, for the one thing observation cannot see: a dependency a
 * template reacts to without reading.
 *
 *     @cachetags('collection:vacancies')
 *
 * A banner that only says "we're hiring" runs no vacancy query, so nothing marks
 * the page as depending on vacancies existing. Declaring it here is the exception;
 * everything a template actually reads is recorded on its own.
 */
final class CacheTagsDirective
{
    public static function compile(string $expression): string
    {
        return '<?php app(\\' . DependencyRecorder::class . '::class)->add(' . $expression . '); ?>';
    }
}
