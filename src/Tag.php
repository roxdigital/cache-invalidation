<?php

declare(strict_types=1);

namespace RoxDigital\CacheInvalidation;

/**
 * The tag vocabulary shared by the recorders and the invalidator.
 *
 * Two kinds of tag exist for anything that can be queried:
 *
 * - An item tag ("entry:{id}") means a page rendered that specific item. Only a
 *   change to that item affects the page.
 * - A list tag ("collection:{handle}") means a page ran a query whose result set
 *   can change when an item is *created*, so it must also react to saves of
 *   items it has never seen.
 *
 * Recording the list tag only for queries that are not pure id lookups is what
 * keeps invalidation targeted without letting a new entry go unnoticed.
 */
final class Tag
{
    /**
     * A page whose dependencies exceeded the recorder's cap. Treated as
     * "depends on everything": the invalidator always includes it, so such a
     * page is cleared by any content save.
     */
    public const OVERFLOW = '*';

    public static function entry(string $id): string
    {
        return 'entry:'.$id;
    }

    public static function collection(string $handle): string
    {
        return 'collection:'.$handle;
    }

    public static function term(string $taxonomy, string $slug): string
    {
        return 'term:'.$taxonomy.'::'.$slug;
    }

    public static function taxonomy(string $handle): string
    {
        return 'taxonomy:'.$handle;
    }

    public static function globalSet(string $handle): string
    {
        return 'global:'.$handle;
    }

    public static function form(string $handle): string
    {
        return 'form:'.$handle;
    }
}
