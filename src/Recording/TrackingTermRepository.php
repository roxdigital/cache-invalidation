<?php

declare(strict_types=1);

namespace RoxDigital\CacheInvalidation\Recording;

use Statamic\Stache\Repositories\TermRepository;
use Statamic\Stache\Stache;

/**
 * Unlike the entry repository, TermRepository::query() constructs its builder
 * directly rather than resolving it from the container, so the repository itself
 * has to be replaced to get a tracking builder in.
 *
 * ensureAssociations() is protected and must still run — it indexes the term
 * store's entry associations, without which taxonomy queries return nothing.
 */
final class TrackingTermRepository extends TermRepository
{
    public function __construct(
        Stache $stache,
        private readonly DependencyRecorder $recorder,
    ) {
        parent::__construct($stache);
    }

    public function query()
    {
        $this->ensureAssociations();

        return new TrackingTermQueryBuilder($this->store, $this->recorder);
    }
}
