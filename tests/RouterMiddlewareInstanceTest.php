<?php

declare(strict_types=1);

namespace MovesCode\Router\Tests;

use MovesCode\Router\Router;
use MovesCode\Router\Tests\Fixtures\{ConfiguredMiddleware, Trace, LegacyAllow, FirstMiddleware, ShortCircuitMiddleware, DoubleNextMiddleware, ThrowBeforeMiddleware};
use PHPUnit\Framework\TestCase;

/**
 * MovesCode | RouterMiddlewareInstanceTest
 *
 * Verifies configured middleware instances alongside existing class registrations.
 *
 * @author Djalma Martins
 * @package MovesCode\Router
 */
final class RouterMiddlewareInstanceTest extends TestCase
{
    protected function setUp(): void
    {
        $_SERVER['REQUEST_METHOD'] = 'GET';
        $_SERVER['REQUEST_URI'] = '/';
        $_SERVER['HTTP_HOST'] = 'example.test';
        $_GET = $_POST = [];
        Trace::$events = [];
        Trace::$result = Trace::$observed = null;
    }

    public function testStandaloneInstanceForEveryHttpMethod(): void
    {
        foreach (['get', 'post', 'put', 'patch', 'delete'] as $method) {
            $instance = new ConfiguredMiddleware('users.manage');
            $router = new Router('https://example.test');
            $_SERVER['REQUEST_METHOD'] = strtoupper($method);
            $router->{$method}('/', static fn () => null, middleware: $instance);
            self::assertTrue($router->dispatch());
            self::assertSame(1, $instance->calls);
        }
    }

    public function testMixedGroupAndRouteKeepOrderAndDeduplicateIdentity(): void
    {
        $first = new ConfiguredMiddleware('read');
        $second = new ConfiguredMiddleware('write');
        $router = new Router('https://example.test');
        $router->group(null, [LegacyAllow::class, $first]);
        $router->get('/', static function (): void { Trace::$events[] = 'handler'; }, middleware: [$first, LegacyAllow::class, $second]);
        self::assertTrue($router->dispatch());
        self::assertSame(['legacy:ok', 'read BEFORE', 'write BEFORE', 'handler', 'write AFTER', 'read AFTER'], Trace::$events);
        self::assertSame(1, $first->calls);
        self::assertSame(1, $second->calls);
    }

    public function testStandaloneGroupInstanceAndReuseWithoutCloning(): void
    {
        $instance = new ConfiguredMiddleware('read');
        $router = new Router('https://example.test');
        $router->group(null, $instance)->get('/', static fn () => null);
        self::assertTrue($router->dispatch());
        self::assertTrue($router->dispatch());
        self::assertSame(2, $instance->calls);
    }

    public function testEqualButDistinctInstancesAreNotDeduplicated(): void
    {
        $first = new ConfiguredMiddleware('read');
        $second = new ConfiguredMiddleware('read');
        $router = new Router('https://example.test');
        $router->get('/', static fn () => null, middleware: [$first, $second]);
        self::assertTrue($router->dispatch());
        self::assertSame(1, $first->calls);
        self::assertSame(1, $second->calls);
    }

    public function testClassAndInstanceOfSameClassRemainDistinct(): void
    {
        $router = new Router('https://example.test');
        $router->get('/', static fn () => null, middleware: [FirstMiddleware::class, new FirstMiddleware()]);
        self::assertTrue($router->dispatch());
        self::assertSame(['A BEFORE', 'A BEFORE', 'A AFTER', 'A AFTER'], Trace::$events);
    }

    public function testInstanceShortCircuitSkipsRemainingChain(): void
    {
        $instance = new ConfiguredMiddleware('read');
        $router = new Router('https://example.test');
        $router->get('/', static function (): never { self::fail('Handler executed'); }, middleware: [new ShortCircuitMiddleware(), $instance, 'MissingMiddleware']);
        self::assertFalse($router->dispatch());
        self::assertNull($router->error());
        self::assertSame(0, $instance->calls);
    }

    public function testInstanceDoubleNextCannotRepeatHandler(): void
    {
        $calls = 0;
        $router = new Router('https://example.test');
        $router->get('/', static function () use (&$calls): void { ++$calls; }, middleware: new DoubleNextMiddleware());
        self::assertFalse($router->dispatch());
        self::assertSame(500, $router->error());
        self::assertSame(1, $calls);
    }

    public function testInstanceExceptionKeepsRouterErrorPolicy(): void
    {
        $router = new Router('https://example.test');
        $router->get('/', static function (): never { self::fail('Handler executed'); }, middleware: new ThrowBeforeMiddleware());
        self::assertFalse($router->dispatch());
        self::assertSame(500, $router->error());
    }

    public function testInvalidValuesInMixedListAreRejected(): void
    {
        foreach ([new \stdClass(), new LegacyAllow(), static fn () => null, 123, null, [], ''] as $invalid) {
            $router = new Router('https://example.test');
            try {
                $router->get('/', static fn () => null, middleware: [new ConfiguredMiddleware('read'), $invalid]);
                self::fail('Invalid middleware accepted');
            } catch (\InvalidArgumentException $error) {
                self::assertStringContainsString('MiddlewareInterface', $error->getMessage());
            }
        }
    }
}
