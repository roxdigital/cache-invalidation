<?php

declare(strict_types=1);

namespace RoxDigital\CacheInvalidation\Console;

use Illuminate\Console\Command;
use RoxDigital\CacheInvalidation\CachedUrls;
use RoxDigital\CacheInvalidation\Graph\DependencyGraph;
use RoxDigital\CacheInvalidation\Invalidation\TagResolver;
use RoxDigital\CacheInvalidation\Tag;
use Statamic\Facades\Entry;
use Statamic\Facades\Form;
use Statamic\Facades\GlobalSet;
use Statamic\Facades\Site;
use Statamic\Facades\Term;

/**
 * Answers "what would clear if I saved this?" before anything is saved — the
 * question the previous config-driven design could not be asked.
 */
final class AffectedCommand extends Command
{
    protected $signature = 'cache-invalidation:affected
        {item : An entry id, term id, global set handle, form handle, or a raw tag}';

    protected $description = 'Show which cached URLs would clear if the given item were saved';

    public function handle(DependencyGraph $graph, TagResolver $resolver, CachedUrls $cached): int
    {
        $item = (string) $this->argument('item');

        [$label, $tags] = $this->resolve($item, $resolver);

        if ($tags === []) {
            $this->components->error("Could not resolve [{$item}] to an entry, term, global set, form or tag.");

            return self::FAILURE;
        }

        $urls = $graph->urlsFor([...$tags, Tag::OVERFLOW]);
        $untracked = $graph->untracked($cached->all());

        $this->line('');
        $this->components->twoColumnDetail('Item', $label);
        $this->components->twoColumnDetail('Tags', implode(' ', $tags));
        $this->line('');

        if ($urls === []) {
            $this->components->warn('No cached page records a dependency on this.');
        } else {
            $this->line('  <fg=cyan>Matched by the graph</> <fg=gray>('.count($urls).')</>');

            foreach ($urls as $url) {
                $this->line('    '.$url);
            }
        }

        $this->line('');

        if ($untracked !== []) {
            $this->components->warn(sprintf(
                'Plus %d untracked cached URL(s), which any save clears until they are re-rendered.',
                count($untracked),
            ));
            $this->line('');
        }

        return self::SUCCESS;
    }

    /**
     * @return array{0: string, 1: list<string>}
     */
    private function resolve(string $item, TagResolver $resolver): array
    {
        if ($entry = Entry::find($item)) {
            return ["entry: {$entry->collectionHandle()}/{$entry->slug()}", $resolver->forItem($entry)];
        }

        if ($term = Term::find($item)) {
            return ["term: {$term->taxonomyHandle()}/{$term->slug()}", $resolver->forItem($term)];
        }

        if ($set = GlobalSet::find($item)) {
            $variables = $set->in(Site::default()->handle());

            return ["global: {$item}", $variables ? $resolver->forItem($variables) : [Tag::globalSet($item)]];
        }

        if ($form = Form::find($item)) {
            return ["form: {$item}", $resolver->forItem($form)];
        }

        // Anything namespaced like a tag is taken at face value, so a dependency
        // can be checked without owning an object to pass in.
        if (str_contains($item, ':')) {
            return ["tag: {$item}", [$item]];
        }

        return ['', []];
    }
}
