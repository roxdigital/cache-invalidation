<?php

declare(strict_types=1);

namespace RoxDigital\CacheInvalidation\Recording;

use Statamic\Tags\Parameters;

/**
 * Makes custom query scopes keep working once a tracking builder is substituted
 * for the one Statamic ships.
 *
 * Statamic keys its scope registry on the exact class name: allowQueryScope()
 * stores under get_class($repository->query()), and Builder::__call() looks up
 * get_class($this). Both ends agree as long as one builder class is involved.
 * Swapping in a subclass splits them — a site that registers its scopes before
 * this addon rebinds the builder (or against a repository this addon has not
 * replaced) files them under the parent class, and every later call to that
 * scope hits Builder::__call() and throws BadMethodCallException.
 *
 * Nothing about a scope is tied to the class it was registered against, so a
 * registration on any ancestor is treated as a registration here. The entry is
 * copied onto this class rather than just followed, so the second call takes the
 * normal path and anything else reading the registry sees the same thing — which
 * is exactly what allowQueryScope() would have written had the order differed.
 */
trait AppliesInheritedScopes
{
    public function canApplyScope($scope): bool
    {
        return parent::canApplyScope($scope) || $this->adoptInheritedScope($scope);
    }

    public function applyScope($scope, Parameters|array $context = [])
    {
        $this->adoptInheritedScope($scope);

        parent::applyScope($scope, $context);
    }

    private function adoptInheritedScope(string $scope): bool
    {
        $scopes = app('statamic.query-scopes');

        if ($scopes->get(static::class, collect())->has($scope)) {
            return true;
        }

        foreach (array_keys(class_parents(static::class)) as $parent) {
            if ($class = $scopes->get($parent, collect())->get($scope)) {
                $scopes->put(static::class, $scopes->get(static::class, collect())->put($scope, $class));

                return true;
            }
        }

        return false;
    }
}
