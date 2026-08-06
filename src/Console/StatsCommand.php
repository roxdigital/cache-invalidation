<?php

declare(strict_types=1);

namespace RoxDigital\CacheInvalidation\Console;

use Illuminate\Console\Command;
use RoxDigital\CacheInvalidation\CachedUrls;
use RoxDigital\CacheInvalidation\Graph\DependencyGraph;

final class StatsCommand extends Command
{
    protected $signature = 'cache-invalidation:stats';

    protected $description = 'Summarise the dependency graph and its coverage of the static cache';

    public function handle(DependencyGraph $graph, CachedUrls $cached): int
    {
        $stats = $graph->stats();
        $cachedUrls = $cached->all();
        $trackedUrls = $graph->urls();

        $untracked = array_values(array_diff($cachedUrls, $trackedUrls));
        $orphaned = array_values(array_diff($trackedUrls, $cachedUrls));

        $this->line('');
        $this->components->twoColumnDetail('Driver', (string) config('cache_invalidation.driver'));
        $this->components->twoColumnDetail('Cached URLs', (string) count($cachedUrls));
        $this->components->twoColumnDetail('Tracked URLs', (string) $stats['urls']);
        $this->components->twoColumnDetail('Distinct tags', (string) $stats['tags']);
        $this->components->twoColumnDetail('Rows', (string) $stats['rows']);
        $this->components->twoColumnDetail(
            'Tags per URL (avg)',
            $stats['urls'] > 0 ? (string) round($stats['rows'] / $stats['urls'], 1) : '0',
        );
        $this->line('');

        if ($untracked !== []) {
            $this->components->warn(sprintf(
                '%d cached URL(s) have no recorded dependencies. Each is cleared by any save until re-rendered.',
                count($untracked),
            ));

            foreach (array_slice($untracked, 0, 10) as $url) {
                $this->line('    '.$url);
            }

            if (count($untracked) > 10) {
                $this->line(sprintf('    … and %d more', count($untracked) - 10));
            }

            $this->line('');
        }

        if ($orphaned !== []) {
            $this->components->info(sprintf(
                '%d tracked URL(s) are no longer cached. Harmless; pruned as they are invalidated.',
                count($orphaned),
            ));
            $this->line('');
        }

        if ($untracked === [] && $cachedUrls !== []) {
            $this->components->info('Every cached URL has recorded dependencies.');
            $this->line('');
        }

        return self::SUCCESS;
    }
}
