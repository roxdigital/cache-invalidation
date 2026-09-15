<?php

declare(strict_types=1);

namespace RoxDigital\CacheInvalidation\Recording;

use Statamic\Fields\Value;
use Statamic\Globals\AugmentedVariables;

/**
 * Records a global set the moment one of its values is read.
 *
 * get() is the single funnel: HasAugmentedInstance routes __get, __call and
 * offsetGet through augmentedValue(), and AbstractAugmented::transientValue()
 * calls get() too, so enumerating a set through toDeferredAugmentedArray() is
 * covered as well — and lazily, since those values resolve on use rather than at
 * cascade hydration.
 *
 * That laziness is what makes global tracking worth having: Statamic hydrates
 * every global set into every view whether or not a template touches it, so
 * tagging at hydration would mark every page as depending on every global. Here
 * a set rendered in the layout ends up on every page and a set rendered by one
 * block ends up on that block's pages, which is exactly the distinction the old
 * `globals_flush_all` config had to be told by hand.
 */
final class TrackingAugmentedVariables extends AugmentedVariables
{
    public function __construct(
        mixed $data,
        private readonly DependencyRecorder $recorder,
        private readonly string $handle,
    ) {
        parent::__construct($data);
    }

    public function get($handle): Value
    {
        if ($this->handle !== '') {
            $this->recorder->globalSet($this->handle);
        }

        return parent::get($handle);
    }
}
