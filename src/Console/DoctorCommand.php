<?php

declare(strict_types=1);

namespace RoxDigital\CacheInvalidation\Console;

use Illuminate\Console\Command;
use RoxDigital\CacheInvalidation\CachedUrls;
use RoxDigital\CacheInvalidation\Graph\DependencyGraph;
use RoxDigital\CacheInvalidation\Graph\NullGraph;
use Statamic\StaticCaching\Cacher;
use Statamic\StaticCaching\Cachers\NullCacher;
use Throwable;

/**
 * Deploy-time check. Exits non-zero when invalidation cannot work, so a broken
 * setup fails the pipeline instead of quietly serving stale pages.
 */
final class DoctorCommand extends Command
{
    protected $signature = 'cache-invalidation:doctor';

    protected $description = 'Verify that cache invalidation can actually work on this environment';

    public function handle(DependencyGraph $graph, CachedUrls $cached, Cacher $cacher): int
    {
        $failed = false;

        $this->line('');

        if (! config('statamic.static_caching.strategy') || $cacher instanceof NullCacher) {
            $this->components->info('Static caching is disabled. Nothing to invalidate.');

            return self::SUCCESS;
        }

        $this->check('Static caching strategy', (string) config('statamic.static_caching.strategy'), true);

        $driver = (string) config('cache_invalidation.driver');
        $this->check('Graph driver', $driver, $driver !== '');

        if ($graph instanceof NullGraph) {
            $this->components->warn('The null driver records nothing, so every save clears the entire cache.');
        }

        try {
            $graph->stats();
            $this->check('Graph reachable', 'yes', true);
        } catch (Throwable $e) {
            $this->check('Graph reachable', $e->getMessage(), false);
            $failed = true;
        }

        if ($driver === 'sqlite') {
            $loaded = extension_loaded('pdo_sqlite');
            $this->check('pdo_sqlite', $loaded ? 'loaded' : 'missing', $loaded);
            $failed = $failed || ! $loaded;

            $path = (string) config('cache_invalidation.sqlite_path');
            $writable = is_writable(is_file($path) ? $path : dirname($path));
            $this->check('Sqlite path writable', $path, $writable);
            $failed = $failed || ! $writable;
        }

        if (! $failed) {
            $cachedUrls = $cached->all();
            $untracked = count(array_diff($cachedUrls, $graph->urls()));

            $this->check(
                'Cached URLs tracked',
                sprintf('%d of %d', count($cachedUrls) - $untracked, count($cachedUrls)),
                true,
            );

            if ($untracked > 0) {
                $this->components->warn(
                    $untracked.' cached URL(s) are untracked and will be cleared by any save until re-rendered. '
                    .'Expected right after a deploy or a full flush.'
                );
            }
        }

        $this->line('');

        if ($failed) {
            $this->components->error('Cache invalidation cannot work on this environment.');

            return self::FAILURE;
        }

        $this->components->info('Cache invalidation is ready.');

        return self::SUCCESS;
    }

    private function check(string $label, string $value, bool $ok): void
    {
        $this->components->twoColumnDetail(
            $label,
            ($ok ? '<info>' : '<fg=red>').$value.($ok ? '</info>' : '</>'),
        );
    }
}
