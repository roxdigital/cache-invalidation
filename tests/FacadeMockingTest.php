<?php

declare(strict_types=1);

namespace RoxDigital\CacheInvalidation\Tests;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Statamic\Facades\Asset;
use Statamic\Facades\Entry;
use Statamic\Facades\Form;
use Statamic\Facades\Nav;
use Statamic\Facades\Term;

/**
 * Host apps mock Statamic's facades in their own tests — `Entry::shouldReceive()`
 * in a middleware test, say. Laravel builds that mock from the class the facade
 * currently resolves to, which is ours once the addon has replaced a repository.
 * Mockery cannot mock a final class, so marking a replaced repository final broke
 * every such test on the site the moment the addon was upgraded.
 */
final class FacadeMockingTest extends TestCase
{
    /**
     * @param  class-string  $facade
     */
    #[Test]
    #[DataProvider('facades')]
    public function a_facade_over_a_replaced_repository_can_still_be_mocked(string $facade, string $method): void
    {
        $facade::shouldReceive($method)->once()->andReturn(null);

        $this->assertNull($facade::{$method}('anything'));
    }

    /**
     * @return array<string, array{class-string, string}>
     */
    public static function facades(): array
    {
        return [
            'Entry' => [Entry::class, 'find'],
            'Term' => [Term::class, 'find'],
            'Nav' => [Nav::class, 'findByHandle'],
            'Form' => [Form::class, 'find'],
            'Asset' => [Asset::class, 'find'],
        ];
    }
}
