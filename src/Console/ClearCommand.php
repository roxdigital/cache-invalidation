<?php

declare(strict_types=1);

namespace RoxDigital\CacheInvalidation\Console;

use Illuminate\Console\Command;
use RoxDigital\CacheInvalidation\CacheTags;

/**
 * The counterpart to `affected`: preview with that, clear with this.
 */
final class ClearCommand extends Command
{
    protected $signature = 'cache-invalidation:clear
        {tags* : One or more tags, e.g. api:reviews or collection:articles}';

    protected $description = 'Clear cached pages carrying the given tags';

    public function handle(CacheTags $tags): int
    {
        /** @var list<string> $given */
        $given = (array) $this->argument('tags');

        $urls = $tags->urlsFor(...$given);

        if ($urls === []) {
            $this->components->info('No cached page carries '.implode(' or ', $given).'.');

            return self::SUCCESS;
        }

        foreach (array_slice($urls, 0, 20) as $url) {
            $this->line('  '.$url);
        }

        if (count($urls) > 20) {
            $this->line(sprintf('  … and %d more', count($urls) - 20));
        }

        $cleared = $tags->invalidate(...$given);

        $this->line('');
        $this->components->info("Cleared {$cleared} cached URL(s).");

        return self::SUCCESS;
    }
}
