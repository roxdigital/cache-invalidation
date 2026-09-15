<?php

declare(strict_types=1);

namespace RoxDigital\CacheInvalidation\Console;

use Illuminate\Console\Command;
use RoxDigital\CacheInvalidation\CachedUrls;
use RoxDigital\CacheInvalidation\Graph\DependencyGraph;
use RoxDigital\CacheInvalidation\Tag;

final class WhyCommand extends Command
{
    protected $signature = 'cache-invalidation:why {url : Absolute URL of a cached page}';

    protected $description = 'Show the content a cached page depends on';

    public function handle(DependencyGraph $graph, CachedUrls $cached): int
    {
        $url = (string) $this->argument('url');

        // Statamic keys the cache on the normalised request URL, which may or may
        // not carry a trailing slash depending on the site. Try both rather than
        // reporting "not found" for a URL that is plainly cached.
        $variant = str_ends_with($url, '/') ? rtrim($url, '/') : $url.'/';

        $tags = $graph->tagsFor($url);

        if ($tags === [] && $variant !== '') {
            $tags = $graph->tagsFor($variant);
        }

        $allCached = $cached->all();
        $isCached = in_array($url, $allCached, true) || in_array($variant, $allCached, true);

        $this->line('');
        $this->line("  <fg=gray>URL</>     {$url}");
        $this->line('  <fg=gray>Cached</>  '.($isCached ? '<info>yes</info>' : '<comment>no</comment>'));
        $this->line('');

        if ($tags === []) {
            $this->components->warn(
                $isCached
                    ? 'Cached but untracked. It will be cleared by the next save of anything, until it is rendered again.'
                    : 'Not in the graph. Render the page once so its dependencies are recorded.'
            );

            return self::SUCCESS;
        }

        if (in_array(Tag::OVERFLOW, $tags, true)) {
            $this->components->warn('This page exceeded the tag cap and is treated as depending on everything.');
            $this->line('');
        }

        $grouped = [];

        foreach ($tags as $tag) {
            $grouped[str_contains($tag, ':') ? strtok($tag, ':') : 'other'][] = $tag;
        }

        ksort($grouped);

        foreach ($grouped as $group => $groupTags) {
            $this->line("  <fg=cyan>{$group}</> <fg=gray>(".count($groupTags).')</>');

            foreach ($groupTags as $tag) {
                $this->line('    '.$tag);
            }

            $this->line('');
        }

        return self::SUCCESS;
    }
}
