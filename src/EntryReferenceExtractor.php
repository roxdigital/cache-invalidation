<?php

declare(strict_types=1);

namespace RoxDigital\CacheInvalidation;

use Illuminate\Support\Collection;
use Illuminate\Support\Str;
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
            return $this->extractFromString($value);
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

        if (is_array($value)) {
            return collect($value)
                ->flatMap(fn (mixed $item): array => $this->extractRecursive($item))
                ->values()
                ->all();
        }

        return [];
    }

    /**
     * A bare id covers the entries fieldtype. The link fieldtype stores
     * "entry::<id>" and Bard stores hrefs as "statamic://entry::<id>", so a rule
     * pointed at either of those fields would otherwise never match.
     *
     * @return array<int, string>
     */
    private function extractFromString(string $value): array
    {
        if (Str::isUuid($value)) {
            return [$value];
        }

        if (! Str::contains($value, 'entry::')) {
            return [];
        }

        $id = Str::after($value, 'entry::');

        return Str::isUuid($id) ? [$id] : [];
    }
}
