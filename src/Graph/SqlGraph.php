<?php

declare(strict_types=1);

namespace RoxDigital\CacheInvalidation\Graph;

use Illuminate\Database\ConnectionInterface;
use Illuminate\Database\Query\Builder;

/**
 * Shared implementation for the sqlite and database drivers.
 *
 * Identity is a sha1 of the URL rather than the URL itself. MySQL cannot index a
 * TEXT column without a prefix length, and a prefix index would make the unique
 * constraint wrong for URLs that share their first 191 characters — which is
 * exactly what long query strings on the same path look like. The full URL is
 * kept alongside for display.
 */
abstract class SqlGraph implements DependencyGraph
{
    /**
     * Rows per insert. Comfortably inside SQLite's default parameter limit at
     * three bound values per row.
     */
    private const CHUNK = 500;

    abstract protected function connection(): ConnectionInterface;

    abstract protected function table(): string;

    public function record(string $url, array $tags): void
    {
        $tags = $this->normalize($tags);
        $hash = $this->hash($url);

        $this->connection()->transaction(function () use ($url, $hash, $tags): void {
            $this->query()->where('url_hash', $hash)->delete();

            foreach (array_chunk($tags, self::CHUNK) as $chunk) {
                $this->query()->insertOrIgnore(array_map(
                    fn (string $tag): array => ['url_hash' => $hash, 'url' => $url, 'tag' => $tag],
                    $chunk,
                ));
            }
        });
    }

    public function urlsFor(array $tags): array
    {
        if (($tags = $this->normalize($tags)) === []) {
            return [];
        }

        $urls = [];

        foreach (array_chunk($tags, self::CHUNK) as $chunk) {
            foreach ($this->query()->whereIn('tag', $chunk)->distinct()->pluck('url') as $url) {
                $urls[$url] = true;
            }
        }

        return array_keys($urls);
    }

    public function tagsFor(string $url): array
    {
        return $this->query()
            ->where('url_hash', $this->hash($url))
            ->orderBy('tag')
            ->pluck('tag')
            ->all();
    }

    public function forget(string $url): void
    {
        $this->query()->where('url_hash', $this->hash($url))->delete();
    }

    public function urls(): array
    {
        return $this->query()->distinct()->orderBy('url')->pluck('url')->all();
    }

    public function flush(): void
    {
        $this->query()->delete();
    }

    public function stats(): array
    {
        return [
            'urls' => $this->query()->distinct()->count('url_hash'),
            'tags' => $this->query()->distinct()->count('tag'),
            'rows' => $this->query()->count(),
        ];
    }

    protected function query(): Builder
    {
        return $this->connection()->table($this->table());
    }

    protected function hash(string $url): string
    {
        return sha1($url);
    }

    /**
     * @param  list<string>  $tags
     * @return list<string>
     */
    private function normalize(array $tags): array
    {
        return array_values(array_unique(array_filter(
            $tags,
            static fn (string $tag): bool => $tag !== '',
        )));
    }
}
