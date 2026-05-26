<?php

declare(strict_types=1);

namespace RoxDigital\CacheInvalidation;

use Illuminate\Support\Collection;
use Statamic\Entries\Entry;

class EntryReferenceExtractor
{
    /**
     * @return array<int, string>
     */
    public function extract(mixed $value): array
    {
        return collect($this->extractRecursive($value))
            ->filter()
            ->unique()
            ->values()
            ->all();
    }

    /**
     * @return array<int, string>
     */
    private function extractRecursive(mixed $value): array
    {
        if (is_string($value)) {
            return [$value];
        }

        if ($value instanceof Entry) {
            return [$value->id()];
        }

        if ($value instanceof Collection) {
            return $value
                ->flatMap(fn (mixed $item): array => $this->extractRecursive($item))
                ->values()
                ->all();
        }

        if (is_array($value) && isset($value['id']) && is_string($value['id'])) {
            return [$value['id']];
        }

        if (is_array($value)) {
            return collect($value)
                ->flatMap(fn (mixed $item): array => $this->extractRecursive($item))
                ->values()
                ->all();
        }

        return [];
    }
}
