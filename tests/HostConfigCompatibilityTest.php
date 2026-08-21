<?php

declare(strict_types=1);

namespace RoxDigital\CacheInvalidation\Tests;

use PHPUnit\Framework\Attributes\Test;
use RoxDigital\CacheInvalidation\Invalidation\GraphInvalidator;
use RoxDigital\CacheInvalidation\Recording\TrackingVariables;
use Statamic\StaticCaching\Invalidator;

/**
 * Shapes a host app's own config can legitimately be in, which the addon has to
 * cope with rather than fatal on.
 */
final class HostConfigCompatibilityTest extends TestCase
{
    /** Set before the application is created; see resolveApplicationConfiguration. */
    public static mixed $rules = null;

    public static mixed $serializableClasses = null;

    public static bool $pinSerializable = false;

    protected function tearDown(): void
    {
        static::$rules = null;
        static::$serializableClasses = null;
        static::$pinSerializable = false;

        parent::tearDown();
    }

    protected function resolveApplicationConfiguration($app): void
    {
        parent::resolveApplicationConfiguration($app);

        $app['config']->set('statamic.static_caching.invalidation.rules', static::$rules);

        if (static::$pinSerializable) {
            $app['config']->set('cache.serializable_classes', static::$serializableClasses);
        }
    }

    /**
     * Statamic documents 'all' as a value for this key and checks for exactly that
     * string in DefaultInvalidator::invalidate(), so a stock config must not fatal.
     */
    #[Test]
    public function it_accepts_the_string_rules_statamic_documents(): void
    {
        static::$rules = 'all';

        $this->refreshApplication();

        $this->assertInstanceOf(GraphInvalidator::class, app(Invalidator::class));
    }

    #[Test]
    public function it_does_not_let_all_reach_the_parent_and_flush_everything(): void
    {
        static::$rules = 'all';

        $this->refreshApplication();

        $invalidator = app(Invalidator::class);

        $rules = (new \ReflectionClass(\Statamic\StaticCaching\DefaultInvalidator::class))
            ->getProperty('rules');

        $this->assertSame(
            [],
            $rules->getValue($invalidator),
            "'all' means flush everything, which is what the graph replaces."
        );
    }

    #[Test]
    public function it_accepts_a_null_rules_key(): void
    {
        static::$rules = null;

        $this->refreshApplication();

        $this->assertInstanceOf(GraphInvalidator::class, app(Invalidator::class));
    }

    #[Test]
    public function it_accepts_an_array_of_rules(): void
    {
        static::$rules = ['collections' => ['pages' => ['urls' => ['/']]]];

        $this->refreshApplication();

        $this->assertInstanceOf(GraphInvalidator::class, app(Invalidator::class));
    }

    /**
     * Globals are cached as TrackingVariables, so that class has to be on the host's
     * allow list or reading a global back yields __PHP_Incomplete_Class.
     */
    #[Test]
    public function it_adds_its_cached_classes_to_the_hosts_allow_list(): void
    {
        static::$pinSerializable = true;
        static::$serializableClasses = ['Statamic\Globals\GlobalSet'];

        $this->refreshApplication();

        $allowed = config('cache.serializable_classes');

        $this->assertIsArray($allowed);
        $this->assertContains(TrackingVariables::class, $allowed);
        $this->assertContains('Statamic\Globals\GlobalSet', $allowed, 'The host list must survive.');
    }

    #[Test]
    public function it_leaves_an_unrestricted_allow_list_alone(): void
    {
        static::$pinSerializable = true;
        static::$serializableClasses = true;

        $this->refreshApplication();

        $this->assertTrue(config('cache.serializable_classes'), 'true already means everything is allowed.');
    }

    #[Test]
    public function it_leaves_an_absent_allow_list_alone(): void
    {
        static::$pinSerializable = true;
        static::$serializableClasses = null;

        $this->refreshApplication();

        $this->assertNull(config('cache.serializable_classes'), 'null already means everything is allowed.');
    }

    #[Test]
    public function it_does_not_add_the_same_class_twice(): void
    {
        static::$pinSerializable = true;
        static::$serializableClasses = [TrackingVariables::class];

        $this->refreshApplication();

        $allowed = config('cache.serializable_classes');

        $this->assertSame(
            [TrackingVariables::class],
            array_values(array_filter($allowed, fn ($c) => $c === TrackingVariables::class)),
        );
    }
}
