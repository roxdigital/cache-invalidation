<?php

declare(strict_types=1);

namespace RoxDigital\CacheInvalidation\Recording;

use Illuminate\Support\Collection;
use Statamic\Contracts\Structures\Nav;
use Statamic\Stache\Repositories\NavigationRepository;
use Statamic\Stache\Stache;

/**
 * Records a navigation when a template resolves it.
 *
 * Statamic's nav tag reaches this through Tags\Structure::structure(), which calls
 * Nav::findByHandle(). find() delegates there too, so one override covers both.
 *
 * all() is hooked as well, and records every nav it hands back. Missing a nav is
 * the one failure that shows: the visible menu would keep a removed item. Over-
 * recording only clears more pages than strictly necessary.
 */
final class TrackingNavigationRepository extends NavigationRepository
{
    public function __construct(
        Stache $stache,
        private readonly DependencyRecorder $recorder,
    ) {
        parent::__construct($stache);
    }

    public function findByHandle($handle): ?Nav
    {
        $nav = parent::findByHandle($handle);

        if ($nav !== null) {
            $this->recorder->nav((string) $nav->handle());
        }

        return $nav;
    }

    public function all(): Collection
    {
        $navs = parent::all();

        foreach ($navs as $nav) {
            $this->recorder->nav((string) $nav->handle());
        }

        return $navs;
    }
}
