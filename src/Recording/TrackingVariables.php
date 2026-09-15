<?php

declare(strict_types=1);

namespace RoxDigital\CacheInvalidation\Recording;

use RoxDigital\CacheInvalidation\Recording\TrackingAugmentedVariables as Augmented;
use Statamic\Contracts\Data\Augmented as AugmentedContract;
use Statamic\Globals\Variables;

/**
 * Statamic's global variables store builds its items with app(Variables::class),
 * so binding this subclass over that contract is enough to observe reads of every
 * global set.
 */
final class TrackingVariables extends Variables
{
    public function newAugmentedInstance(): AugmentedContract
    {
        return new Augmented($this, app(DependencyRecorder::class), (string) $this->handle());
    }
}
