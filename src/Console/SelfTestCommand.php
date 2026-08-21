<?php

declare(strict_types=1);

namespace RoxDigital\CacheInvalidation\Console;

use Illuminate\Console\Command;
use Illuminate\Contracts\Queue\ShouldQueue;
use RoxDigital\CacheInvalidation\Cachers\TrackingApplicationCacher;
use RoxDigital\CacheInvalidation\Cachers\TrackingFileCacher;
use RoxDigital\CacheInvalidation\Invalidation\GraphInvalidator;
use RoxDigital\CacheInvalidation\Recording\DependencyRecorder;
use Statamic\Facades\Entry;
use Statamic\Facades\Form;
use Statamic\Facades\GlobalSet;
use Statamic\Facades\Nav;
use Statamic\Facades\Site;
use Statamic\Statamic;
use Statamic\StaticCaching\Cacher;
use Statamic\StaticCaching\Cachers\NullCacher;
use Statamic\StaticCaching\Invalidator;
use Throwable;

/**
 * Verifies the Statamic integration from inside a site, against the Statamic version
 * that site actually has installed.
 *
 * This exists because the addon's own test suite cannot be run from a site — its dev
 * dependencies are never installed there — and because upgrading Statamic is exactly
 * when you want to know. Every seam is checked twice:
 *
 * - Structurally, that the methods and properties still exist. Catches a rename or
 *   removal, which is the likely break.
 * - Behaviourally, by recording against the site's own content and asserting the
 *   result. Catches the dangerous kind: a method that still exists but now behaves
 *   differently, which reflection cannot see and which is how every bug found while
 *   building this actually presented.
 *
 * Read-only. It queries content and inspects the recorder; it never writes to the
 * graph or the cache.
 */
final class SelfTestCommand extends Command
{
    protected $signature = 'cache-invalidation:selftest';

    protected $description = 'Verify the addon still fits this Statamic version (run after upgrading Statamic)';

    private int $passed = 0;

    private int $failed = 0;

    private int $skipped = 0;

    public function handle(DependencyRecorder $recorder): int
    {
        $this->line('');
        $this->components->info('Structure — do the classes and members still exist?');

        foreach ($this->structuralChecks() as $label => $ok) {
            $this->report($label, $ok ? 'ok' : 'missing', $ok);
        }

        $this->line('');
        $this->components->info('Behaviour — does recording still produce the right tags?');

        $this->behaviouralChecks($recorder);

        $recorder->reset();

        $this->line('');

        if ($this->failed > 0) {
            $this->components->error(sprintf(
                '%d check(s) failed. The addon does not fit this Statamic version — do not deploy it.',
                $this->failed,
            ));

            return self::FAILURE;
        }

        $this->components->info(sprintf(
            '%d checks passed%s. The addon fits this Statamic version.',
            $this->passed,
            $this->skipped > 0 ? ", {$this->skipped} skipped for lack of content" : '',
        ));

        return self::SUCCESS;
    }

    /**
     * One entry per member, never a compound check.
     *
     * A single label covering several members tells you something broke but not
     * what, which is exactly the wrong trade when this is meant to unblock someone
     * mid-upgrade.
     *
     * @return array<string, bool>
     */
    private function structuralChecks(): array
    {
        $methods = [
            \Statamic\Stache\Query\EntryQueryBuilder::class => ['getFilteredKeys', 'getItems'],
            \Statamic\Stache\Query\TermQueryBuilder::class => ['getFilteredKeys', 'getItems'],
            \Statamic\Stache\Repositories\EntryRepository::class => ['findByUri'],
            \Statamic\Stache\Repositories\TermRepository::class => ['query', 'ensureAssociations'],
            \Statamic\Stache\Repositories\NavigationRepository::class => ['findByHandle', 'all'],
            \Statamic\Stache\Repositories\NavTreeRepository::class => ['find'],
            \Statamic\Globals\Variables::class => ['newAugmentedInstance'],
            \Statamic\Globals\AugmentedVariables::class => ['get'],
            \Statamic\Forms\FormRepository::class => ['find'],
            \Statamic\Tags\Nav::class => ['structure'],
            \Statamic\StaticCaching\Cachers\ApplicationCacher::class => ['cachePage'],
            \Statamic\StaticCaching\Cachers\FileCacher::class => ['cachePage'],
            \Statamic\StaticCaching\Cachers\AbstractCacher::class => ['getUrl', 'isExcluded', 'getUrls', 'getDomains'],
            \Statamic\StaticCaching\DefaultInvalidator::class => ['getItemUrls'],
            \Statamic\StaticCaching\StaticCacheManager::class => ['extend', 'cacheStore'],
        ];

        $properties = [
            \Statamic\Stache\Query\EntryQueryBuilder::class => ['collections'],
            \Statamic\Stache\Query\TermQueryBuilder::class => ['taxonomies'],
            \Statamic\Query\Builder::class => ['wheres'],
            \Statamic\Stache\Repositories\TermRepository::class => ['store'],
        ];

        $checks = [];

        foreach ($methods as $class => $names) {
            foreach ($names as $name) {
                $checks[$this->shortName($class)."::{$name}()"] = class_exists($class) && method_exists($class, $name);
            }
        }

        foreach ($properties as $class => $names) {
            foreach ($names as $name) {
                $checks[$this->shortName($class).'::$'.$name] = class_exists($class) && property_exists($class, $name);
            }
        }

        $checks['Invalidate is queued'] = is_subclass_of(\Statamic\StaticCaching\Invalidate::class, ShouldQueue::class);
        $checks['Tag classes resolve via the container'] = is_string(app('statamic.tags')->get('nav') ?? null);

        return $checks;
    }

    private function shortName(string $class): string
    {
        return str_replace('Statamic\\', '', $class);
    }

    private function behaviouralChecks(DependencyRecorder $recorder): void
    {
        $cacher = app(Cacher::class);

        if ($cacher instanceof NullCacher) {
            $this->report('Static caching enabled', 'disabled — behaviour not checked', true);
            $this->skipped++;

            return;
        }

        $this->report(
            'Tracking cacher is active',
            class_basename($cacher),
            $cacher instanceof TrackingApplicationCacher || $cacher instanceof TrackingFileCacher,
        );

        $this->report(
            'Graph invalidator is active',
            class_basename(app(Invalidator::class)),
            app(Invalidator::class) instanceof GraphInvalidator,
        );

        $entry = Entry::query()->limit(1)->first();

        if (! $entry) {
            $this->skip('Entry recording', 'no entries');

            return;
        }

        $collection = (string) $entry->collectionHandle();

        // An id lookup must record that entry and nothing else. If a list tag leaks
        // in here, every page showing one entry depends on its whole collection.
        $tags = $this->record($recorder, fn () => Entry::find($entry->id()));
        $this->report(
            'Entry::find records only that entry',
            $this->summarise($tags),
            $tags === ['entry:'.$entry->id()],
        );

        // A list query must record the collection, or an entry created later would
        // never reach the pages that list it.
        $tags = $this->record($recorder, fn () => Entry::query()->where('collection', $collection)->limit(5)->get());
        $this->report(
            'A collection query records the collection',
            $this->summarise($tags),
            in_array('collection:'.$collection, $tags, true),
        );

        // count() bypasses get() in Stache\Query\Builder.
        $tags = $this->record($recorder, fn () => Entry::query()->where('collection', $collection)->count());
        $this->report(
            'count() records the collection',
            $this->summarise($tags),
            in_array('collection:'.$collection, $tags, true),
        );

        $this->checkUrlResolution($recorder);
        $this->checkNav($recorder);
        $this->checkGlobal($recorder);
        $this->checkForm($recorder);
    }

    /**
     * The regression that made every page depend on its whole collection: resolving a
     * URL in a structured collection validates the collection tree, which plucks
     * every entry in it.
     */
    private function checkUrlResolution(DependencyRecorder $recorder): void
    {
        $entry = Entry::query()
            ->limit(50)
            ->get()
            ->first(fn ($entry) => $entry->uri() !== null && $entry->collection()->hasStructure());

        if (! $entry) {
            $this->skip('URL resolution', 'no entry in a structured collection');

            return;
        }

        // Resolved before recording starts. uri() itself consults the collection tree
        // for a structured collection, so calling it inside the measured block would
        // attribute Statamic's own tree validation to this check and fail it for the
        // wrong reason — non-deterministically, since the tree is memoised per
        // process.
        $uri = (string) $entry->uri();
        $site = Site::current()->handle();

        $tags = $this->record($recorder, fn () => Entry::findByUri($uri, $site));

        $this->report(
            'Resolving a URL records only the entry',
            $this->summarise($tags),
            $tags === ['entry:'.$entry->id()],
        );
    }

    private function checkNav(DependencyRecorder $recorder): void
    {
        $nav = Nav::all()->first(fn ($nav) => $nav->in(Site::current()->handle()) !== null);

        if (! $nav) {
            $this->skip('Navigation recording', 'no navigations');

            return;
        }

        $handle = (string) $nav->handle();

        try {
            $tags = $this->record($recorder, fn () => Statamic::tag('nav:'.$handle)->fetch());
        } catch (Throwable $e) {
            $this->report('Navigation recording', 'threw: '.$e->getMessage(), false);

            return;
        }

        // A nav must be represented by itself, not by the pages it links to,
        // or renaming any page in a menu clears the whole site.
        $this->report(
            'Rendering a nav records the nav, not its pages',
            $this->summarise($tags),
            in_array('nav:'.$handle, $tags, true)
                && $tags === array_values(array_filter($tags, fn (string $t): bool => ! str_starts_with($t, 'entry:'))),
        );
    }

    private function checkGlobal(DependencyRecorder $recorder): void
    {
        $set = GlobalSet::all()
            ->map(fn ($set) => $set->in(Site::current()->handle()))
            ->filter()
            ->first(fn ($variables) => $variables->data()->keys()->isNotEmpty());

        if (! $set) {
            $this->skip('Global recording', 'no globals with data');

            return;
        }

        $handle = (string) $set->globalSet()->handle();
        $key = (string) $set->data()->keys()->first();

        $onFetch = $this->record($recorder, fn () => GlobalSet::find($handle)->in(Site::current()->handle()));
        $onRead = $this->record($recorder, function () use ($handle, $key): void {
            GlobalSet::find($handle)->in(Site::current()->handle())->{$key};
        });

        // Recorded on read, not on hydration — otherwise every page would depend on
        // every global.
        $this->report(
            'A global is recorded on read only',
            $this->summarise($onRead),
            $onFetch === [] && in_array('global:'.$handle, $onRead, true),
        );
    }

    private function checkForm(DependencyRecorder $recorder): void
    {
        $form = Form::all()->first();

        if (! $form) {
            $this->skip('Form recording', 'no forms');

            return;
        }

        $handle = (string) $form->handle();
        $tags = $this->record($recorder, fn () => Form::find($handle));

        $this->report('Resolving a form records it', $this->summarise($tags), $tags === ['form:'.$handle]);
    }

    /**
     * @return list<string>
     */
    private function record(DependencyRecorder $recorder, callable $callback): array
    {
        $recorder->reset();

        try {
            $callback();
        } catch (Throwable) {
            // Reported by the check itself through an empty tag set.
        }

        return $recorder->tags();
    }

    /**
     * @param  list<string>  $methods
     */
    private function hasMethods(string $class, array $methods): bool
    {
        if (! class_exists($class)) {
            return false;
        }

        foreach ($methods as $method) {
            if (! method_exists($class, $method)) {
                return false;
            }
        }

        return true;
    }

    private function hasProperty(string $class, string $property): bool
    {
        return class_exists($class) && property_exists($class, $property);
    }

    /**
     * @param  list<string>  $tags
     */
    private function summarise(array $tags): string
    {
        if ($tags === []) {
            return 'nothing recorded';
        }

        return count($tags) > 3
            ? implode(' ', array_slice($tags, 0, 3)).sprintf(' +%d', count($tags) - 3)
            : implode(' ', $tags);
    }

    private function report(string $label, string $value, bool $ok): void
    {
        $ok ? $this->passed++ : $this->failed++;

        $this->components->twoColumnDetail(
            $label,
            ($ok ? '<info>' : '<fg=red>').$value.($ok ? '</info>' : '</>'),
        );
    }

    private function skip(string $label, string $why): void
    {
        $this->skipped++;

        $this->components->twoColumnDetail($label, "<comment>skipped — {$why}</comment>");
    }
}
