<?php

declare(strict_types=1);

namespace RoxDigital\CacheInvalidation\Http;

use Closure;
use Illuminate\Http\Request;
use RoxDigital\CacheInvalidation\Recording\DependencyRecorder;
use Symfony\Component\HttpFoundation\Response;

/**
 * Exposes a render's recorded dependencies as X-Cache-Tags, so you can read why
 * a page will or will not be invalidated straight from devtools.
 *
 * Pushed onto the end of the statamic.web group, which puts it inside Statamic's
 * cache middleware: its after-phase runs once rendering is complete but before
 * the page is cached, so the tag set is final. A cached hit short-circuits the
 * outer middleware and never reaches here — by design, since the tags belong to
 * the render that produced the cache entry.
 */
final class AddCacheTagsHeader
{
    /**
     * Headers have practical size limits and an overview page can record
     * thousands of tags, so long sets are truncated with a count.
     */
    private const MAX_TAGS = 60;

    public function handle(Request $request, Closure $next): mixed
    {
        $response = $next($request);

        if (! config('cache_invalidation.debug') || ! $response instanceof Response) {
            return $response;
        }

        $tags = app(DependencyRecorder::class)->tags();
        $total = count($tags);

        if ($total > self::MAX_TAGS) {
            $tags = array_slice($tags, 0, self::MAX_TAGS);
            $tags[] = sprintf('… (%d total)', $total);
        }

        $response->headers->set('X-Cache-Tags', implode(' ', $tags));

        return $response;
    }
}
