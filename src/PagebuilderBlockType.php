<?php

declare(strict_types=1);

namespace RoxDigital\CacheInvalidation;

enum PagebuilderBlockType: string
{
    case ReusableBlock = 'reusable_block';

    /**
     * @param  array<string, mixed>  $block
     */
    public function matches(array $block): bool
    {
        return ($block['type'] ?? null) === $this->value;
    }
}
