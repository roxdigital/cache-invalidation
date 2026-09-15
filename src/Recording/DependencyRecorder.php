<?php

declare(strict_types=1);

namespace RoxDigital\CacheInvalidation\Recording;

use RoxDigital\CacheInvalidation\Tag;

/**
 * Accumulates the tags a single render depends on.
 *
 * Bound as a singleton, so in PHP-FPM its lifetime is the request. Long-running
 * processes (queue workers, Octane) must call reset() between units of work; the
 * service provider wires that up.
 *
 * Nothing here decides what is worth recording — the tracking query builders and
 * repositories do. This only collects, deduplicates and caps.
 */
final class DependencyRecorder
{
    /**
     * Beyond this, a page is recorded as depending on everything instead. An
     * overview listing thousands of entries would otherwise write thousands of
     * rows per render for no invalidation benefit: it already carries the
     * collection tag, which covers every one of them.
     */
    private const MAX_TAGS = 2000;

    /** @var array<string, true> */
    private array $tags = [];

    private bool $overflowed = false;

    private bool $suppressing = false;

    /**
     * Ignore everything recorded while the callback runs.
     *
     * For Statamic's own resolution machinery, which queries content to work out
     * *which* entry a request is for. Those reads are not rendered output, and
     * treating them as such attributes dependencies to a page that it does not
     * display — see TrackingEntryRepository.
     */
    public function suppressed(callable $callback): mixed
    {
        $previous = $this->suppressing;
        $this->suppressing = true;

        try {
            return $callback();
        } finally {
            $this->suppressing = $previous;
        }
    }

    public function add(string ...$tags): void
    {
        if ($this->suppressing || $this->overflowed) {
            return;
        }

        foreach ($tags as $tag) {
            if ($tag === '' || isset($this->tags[$tag])) {
                continue;
            }

            if (count($this->tags) >= self::MAX_TAGS) {
                $this->overflow();

                return;
            }

            $this->tags[$tag] = true;
        }
    }

    /**
     * @param  iterable<mixed>  $ids
     */
    public function entries(iterable $ids): void
    {
        foreach ($ids as $id) {
            if (is_string($id) && $id !== '') {
                $this->add(Tag::entry($id));
            }
        }
    }

    /**
     * @param  iterable<mixed>  $handles
     */
    public function collections(iterable $handles): void
    {
        foreach ($handles as $handle) {
            if (is_string($handle) && $handle !== '') {
                $this->add(Tag::collection($handle));
            }
        }
    }

    public function term(string $taxonomy, string $slug): void
    {
        $this->add(Tag::term($taxonomy, $slug));
    }

    /**
     * @param  iterable<mixed>  $handles
     */
    public function taxonomies(iterable $handles): void
    {
        foreach ($handles as $handle) {
            if (is_string($handle) && $handle !== '') {
                $this->add(Tag::taxonomy($handle));
            }
        }
    }

    public function globalSet(string $handle): void
    {
        $this->add(Tag::globalSet($handle));
    }

    public function form(string $handle): void
    {
        $this->add(Tag::form($handle));
    }

    public function nav(string $handle): void
    {
        $this->add(Tag::nav($handle));
    }

    /**
     * @return list<string>
     */
    public function tags(): array
    {
        return array_keys($this->tags);
    }

    public function isEmpty(): bool
    {
        return $this->tags === [];
    }

    public function overflowed(): bool
    {
        return $this->overflowed;
    }

    public function reset(): void
    {
        $this->tags = [];
        $this->overflowed = false;
        $this->suppressing = false;
    }

    /**
     * Collapse to the overflow tag and stop collecting. Deliberately lossy in
     * the safe direction: the page is now cleared by any content save, which
     * beats a silently truncated tag set that would leave it stale.
     */
    private function overflow(): void
    {
        $this->tags = [Tag::OVERFLOW => true];
        $this->overflowed = true;
    }
}
