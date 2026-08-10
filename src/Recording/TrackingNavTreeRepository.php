<?php

declare(strict_types=1);

namespace RoxDigital\CacheInvalidation\Recording;

use Statamic\Contracts\Structures\Tree;
use Statamic\Stache\Repositories\NavTreeRepository;
use Statamic\Stache\Stache;

/**
 * A second, independent recording point for navigations.
 *
 * Rendering a nav always needs its tree, so this catches a template that already
 * holds a Nav object and never goes back through the repository. Belt and braces
 * on purpose: an unrecorded nav means a stale menu, which is the one failure a
 * visitor notices immediately.
 */
final class TrackingNavTreeRepository extends NavTreeRepository
{
    public function __construct(
        Stache $stache,
        private readonly DependencyRecorder $recorder,
    ) {
        parent::__construct($stache);
    }

    public function find(string $handle, string $site): ?Tree
    {
        $tree = parent::find($handle, $site);

        if ($tree !== null) {
            $this->recorder->nav($handle);
        }

        return $tree;
    }
}
