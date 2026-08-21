<?php

declare(strict_types=1);

namespace RoxDigital\CacheInvalidation\Recording;

use Statamic\Forms\FormRepository;

/**
 * Records a form when it is resolved, which is how the form fieldtype augments:
 * Forms\Fieldtype::augmentValue() calls Form::find() with the stored handle.
 *
 * Only find() is hooked. all() resolves through `self::find()`, which binds to
 * the parent class rather than this one — convenient, since all() is control
 * panel territory and should not mark a page as depending on every form.
 *
 * Note that Statamic's own form templates wrap rendering in @nocache, so a
 * blueprint change already shows up without invalidation. This still matters for
 * form config that affects markup outside the nocache region, and for templates
 * that render a form directly.
 */
final class TrackingFormRepository extends FormRepository
{
    public function __construct(
        private readonly DependencyRecorder $recorder,
    ) {}

    public function find($handle)
    {
        $form = parent::find($handle);

        if ($form !== null && is_string($handle)) {
            $this->recorder->form($handle);
        }

        return $form;
    }
}
