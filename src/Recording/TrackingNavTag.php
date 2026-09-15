<?php

declare(strict_types=1);

namespace RoxDigital\CacheInvalidation\Recording;

use RoxDigital\CacheInvalidation\Tag;
use Statamic\Contracts\Structures\Structure as StructureContract;
use Statamic\Support\Str;
use Statamic\Tags\Nav;

/**
 * Represents a rendered navigation by the nav itself, not by the entries it links
 * to.
 *
 * Statamic's TreeBuilder resolves every linked entry to build the menu, so without
 * this a nav in the shared layout put an `entry:` tag for each of its items on
 * every page. Renaming any page in the menu then cleared the entire site.
 *
 * That was defensible — the menu does show the old title until the page is
 * re-rendered — but it is not the trade-off this addon makes. A page save clears
 * where that page is genuinely rendered as content; the menu is the navigation's
 * concern, so it clears when the navigation is saved.
 *
 * The consequence is deliberate and worth knowing: rename a page and its menu
 * entry stays stale on already-cached pages until they are cleared for some other
 * reason. Save the navigation to force it.
 *
 * Statamic resolves tag classes through the container (Tags\Loader::init), so
 * binding this over Tags\Nav is enough to reach both `{{ nav:handle }}` and
 * `{{ nav for="handle" }}`.
 */
final class TrackingNavTag extends Nav
{
    protected function structure($handle)
    {
        $recorder = app(DependencyRecorder::class);

        $resolved = $handle instanceof StructureContract ? $handle->handle() : $handle;

        $value = $recorder->suppressed(fn () => parent::structure($handle));

        // Suppression also drops the nav the repository would have recorded, so it
        // is re-added here — this is the one tag that should survive.
        if (is_string($resolved) && $resolved !== '') {
            Str::startsWith($resolved, 'collection::')
                // A collection structure is that collection's tree, so its list
                // tag is the honest dependency.
                ? $recorder->add(Tag::collection(Str::after($resolved, 'collection::')))
                : $recorder->nav($resolved);
        }

        return $value;
    }
}
