<?php

declare(strict_types=1);

namespace RoxDigital\CacheInvalidation\Cachers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use RoxDigital\CacheInvalidation\Graph\DependencyGraph;
use RoxDigital\CacheInvalidation\Recording\DependencyRecorder;
use Throwable;

/**
 * Writes the graph row at the moment a page enters the static cache.
 *
 * cachePage() is the only place that knows both the canonical URL and that the
 * page is actually being cached, and it is reached identically by the file and
 * application drivers — which is why this hooks the concrete cachers by subclass
 * rather than decorating the Cacher binding. Statamic's cache middleware
 * branches on `instanceof ApplicationCacher`, `FileCacher` and `NullCacher`; a
 * decorator would silently change which status codes get cached.
 */
trait RecordsDependencies
{
    public function cachePage(Request $request, $content)
    {
        parent::cachePage($request, $content);

        $url = $this->getUrl($request);

        // Mirrors the parent's own early return. An excluded URL is never
        // cached, so it must not gain a graph row either.
        if ($this->isExcluded($url)) {
            return;
        }

        // Deliberately no reset() here. While handling an error the middleware
        // caches the shared error URL and then the real URL within one request,
        // and both rows need the full tag set.
        try {
            app(DependencyGraph::class)->record($url, app(DependencyRecorder::class)->tags());
        } catch (Throwable $e) {
            // Recording is best effort: a visitor's page render must not fail
            // because the graph could not be written. A missing row leaves the
            // URL untracked, which the safety net clears on the next save, so
            // the failure mode is over-invalidation rather than stale content.
            Log::warning('Could not record cache dependencies for ['.$url.']: '.$e->getMessage());
        }
    }
}
