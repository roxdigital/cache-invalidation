<?php

declare(strict_types=1);

namespace RoxDigital\CacheInvalidation\Console;

use Illuminate\Console\Command;
use RoxDigital\CacheInvalidation\CachedUrls;
use RoxDigital\CacheInvalidation\Graph\DependencyGraph;

/**
 * Bounds the graph against what is actually cached.
 *
 * Nothing removes a URL from the graph when it quietly falls out of the static
 * cache — rows are only ever replaced by rendering the same URL again. Over
 * months the graph comes to describe mostly pages that no longer exist, which
 * both inflates the file and makes `untracked()` compare against rows that can
 * never match. Safe to schedule; it only ever removes rows for URLs the cacher
 * no longer holds.
 */
final class PruneCommand extends Command
{
    protected $signature = 'cache-invalidation:prune
        {--dry-run : Report what would be removed without changing anything}';

    protected $description = 'Drop graph rows for URLs that are no longer in the static cache';

    public function handle(DependencyGraph $graph, CachedUrls $cached): int
    {
        if (! $cached->supported()) {
            $this->components->warn(
                'The configured cacher cannot list what it holds, so there is no safe set of URLs to keep.'
            );

            return self::FAILURE;
        }

        $keep = $cached->all();
        $before = $graph->stats();

        // Worked out here rather than behind another contract method: urls() and
        // prune() between them already say everything a caller needs.
        $keepSet = array_flip($keep);

        $stale = count(array_filter(
            $graph->urls(),
            static fn (string $url): bool => ! isset($keepSet[$url]),
        ));

        if ($stale === 0) {
            $this->components->info(
                sprintf('Nothing to prune. %d tracked URL(s), all still cached.', $before['urls'])
            );

            return self::SUCCESS;
        }

        if ($this->option('dry-run')) {
            $this->components->info(sprintf(
                '%d of %d tracked URL(s) are no longer cached and would be pruned.',
                $stale,
                $before['urls'],
            ));

            return self::SUCCESS;
        }

        $removed = $graph->prune($keep);
        $graph->compact();

        $after = $graph->stats();

        $this->components->info(sprintf(
            'Pruned %d URL(s) and %d row(s). Graph now holds %d URL(s) across %d row(s).',
            $before['urls'] - $after['urls'],
            $removed,
            $after['urls'],
            $after['rows'],
        ));

        return self::SUCCESS;
    }
}
