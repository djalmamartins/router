<?php

declare(strict_types=1);

namespace MovesCode\Router\Tests;

use MovesCode\Router\Router;
use MovesCode\Router\Tests\Fixtures\{Trace, FirstMiddleware, SecondMiddleware, ShortCircuitMiddleware, ThrowBeforeMiddleware, ThrowAfterMiddleware, DoubleNextMiddleware, TransformMiddleware, CatchMiddleware, LegacyAllow, LegacyDeny, ConstructorProbe, Controller};
use PHPUnit\Framework\TestCase;

/**
 * MovesCode | RouterPipelineTest
 *
 * Verifies pipeline integration and coexistence with legacy middleware.
 *
 * @author Djalma Martins
 * @package MovesCode\Router
 */
final class RouterPipelineTest extends TestCase
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

    public function testSingleMiddlewareSeesAllHandlerReturnTypes(): void
    {
        foreach ([null, false, 0, 'done', new \stdClass()] as $result) {
            $router = new Router('https://example.test');
            $router->get('/', static fn () => $result, middleware: FirstMiddleware::class);
            self::assertTrue($router->dispatch());
            self::assertSame($result, Trace::$observed);
            self::assertNull($router->error());
        }
    }

    public function testBeforeAfterOrderAndExactlyOnceExecution(): void
    {
        $router = new Router('https://example.test');
        $router->get('/', static function (): string {
            Trace::$events[] = 'handler';
            return 'done';
        }, middleware: [FirstMiddleware::class, SecondMiddleware::class]);
        self::assertTrue($router->dispatch());
        self::assertSame(['A BEFORE', 'B BEFORE', 'handler', 'B AFTER', 'A AFTER'], Trace::$events);
        self::assertSame('done', Trace::$observed);
    }

    public function testShortCircuitSkipsHandlerAndResolutionRegardlessOfReturn(): void
    {
        foreach ([null, false, true, 0, 'response', new \stdClass()] as $result) {
            Trace::$result = $result;
            Trace::$events = [];
            $router = new Router('https://example.test');
            $router->get('/', static function (): never { self::fail('Handler executed'); }, middleware: [ShortCircuitMiddleware::class, ConstructorProbe::class, 'MissingMiddleware']);
            self::assertFalse($router->dispatch());
            self::assertNull($router->error());
            self::assertSame(['short'], Trace::$events);
        }
    }

    public function testExceptionsBeforeAndAfterNextBecomeError500(): void
    {
        foreach ([ThrowBeforeMiddleware::class => 0, ThrowAfterMiddleware::class => 1] as $middleware => $expectedCalls) {
            $calls = 0;
            $router = new Router('https://example.test');
            $router->get('/', static function () use (&$calls): void { ++$calls; }, middleware: $middleware);
            self::assertFalse($router->dispatch());
            self::assertSame(500, $router->error());
            self::assertSame($expectedCalls, $calls);
        }
    }

    public function testHandlerExceptionTravelsThroughMiddlewareBeforeRouterBoundary(): void
    {
        $error = new \RuntimeException('private error');
        $router = new Router('https://example.test');
        $router->get('/', static fn () => throw $error, middleware: CatchMiddleware::class);
        self::assertFalse($router->dispatch());
        self::assertNull($router->error());
        self::assertSame($error, Trace::$observed);
    }

    public function testUncaughtHandlerExceptionKeepsError500(): void
    {
        $exception = new \Error('private error');

        $router = new Router('https://example.test');

        $router->get(
            '/',
            static fn () => throw $exception,
            middleware: FirstMiddleware::class
        );

        self::assertFalse(
            $router->dispatch()
        );

        self::assertSame(
            500,
            $router->error()
        );

        self::assertSame(
            $exception,
            $router->exception()
        );

        self::assertSame(
            ['A BEFORE'],
            Trace::$events
        );
    }

    public function testGroupAndRouteMiddlewarePreserveParametersAndNamedRoutes(): void
    {
        $_SERVER['REQUEST_URI'] = '/admin/42';
        $router = new Router('https://example.test');
        $router->group('admin', FirstMiddleware::class)->get('/{id}', static function (array $data, Router $actual) use ($router): string {
            self::assertSame(['id' => '42'], $data);
            self::assertSame($router, $actual);
            Trace::$events[] = 'handler';
            return $data['id'];
        }, 'admin.item', [FirstMiddleware::class, SecondMiddleware::class]);
        self::assertTrue($router->dispatch());
        self::assertSame(['A BEFORE', 'B BEFORE', 'handler', 'B AFTER', 'A AFTER'], Trace::$events);
        self::assertSame('42', Trace::$observed);
        self::assertSame('https://example.test/admin/8', $router->route('admin.item', ['id' => 8]));
        $current = $router->current();
        self::assertNotNull($current);
        self::assertSame('admin.item', $current->name);
    }

    public function testGroupMiddlewareWithoutRouteMiddleware(): void
    {
        $router = new Router('https://example.test');
        $router->group(null, FirstMiddleware::class)->get('/', static fn () => 'group');
        self::assertTrue($router->dispatch());
        self::assertSame(['A BEFORE', 'A AFTER'], Trace::$events);
        self::assertSame('group', Trace::$observed);
    }

    public function testExistingControllerReturnReachesMiddleware(): void
    {
        $result = new \stdClass();
        Trace::$result = $result;
        $router = new Router('https://example.test');
        $router->namespace('MovesCode\\Router\\Tests\\Fixtures')->get('/', 'Controller:show', middleware: FirstMiddleware::class);
        self::assertTrue($router->dispatch());
        self::assertSame(['A BEFORE', 'controller', 'A AFTER'], Trace::$events);
        self::assertSame($result, Trace::$observed);
    }

    public function testLegacyAndNewMiddlewareCanBeInterleaved(): void
    {
        $router = new Router('https://example.test');
        $router->get('/', static function (): string { Trace::$events[] = 'handler'; return 'done'; }, middleware: [FirstMiddleware::class, LegacyAllow::class, SecondMiddleware::class]);
        self::assertTrue($router->dispatch());
        self::assertSame(['A BEFORE', 'legacy:ok', 'B BEFORE', 'handler', 'B AFTER', 'A AFTER'], Trace::$events);
    }

    public function testLegacyDenialStillUnwindsOuterMiddleware(): void
    {
        $router = new Router('https://example.test');
        $router->get('/', static function (): never { self::fail('Handler executed'); }, middleware: [FirstMiddleware::class, LegacyDeny::class, SecondMiddleware::class]);
        self::assertFalse($router->dispatch());
        self::assertNull($router->error());
        self::assertSame(['A BEFORE', 'denied', 'A AFTER'], Trace::$events);
        self::assertFalse(Trace::$observed);
    }

    public function testDoubleNextDoesNotRepeatDownstream(): void
    {
        $calls = 0;
        $router = new Router('https://example.test');
        $router->get('/', static function () use (&$calls): void { ++$calls; }, middleware: [DoubleNextMiddleware::class, SecondMiddleware::class]);
        self::assertFalse($router->dispatch());
        self::assertSame(500, $router->error());
        self::assertSame(1, $calls);
        self::assertSame(['B BEFORE', 'B AFTER'], Trace::$events);
    }

    public function testMiddlewareCanTransformResultsWithoutChangingDispatchType(): void
    {
        $router = new Router('https://example.test');
        $router->get('/', static fn () => 'done', middleware: [FirstMiddleware::class, TransformMiddleware::class]);
        self::assertTrue($router->dispatch());
        self::assertSame(['wrapped' => 'done'], Trace::$observed);
    }

    public function testRepeatedDispatchAfterExceptionAndIndependentRouters(): void
    {
        $fail = true;
        $exception = new \RuntimeException('first dispatch failed');

        $first = new Router('https://example.test');

        $first->get(
            '/',
            static function () use (&$fail, $exception): string {
                if ($fail) {
                    $fail = false;

                    throw $exception;
                }

                return 'first';
            },
            middleware: FirstMiddleware::class
        );

        $second = new Router('https://example.test');

        $second->get(
            '/',
            static fn () => 'second'
        );

        self::assertFalse(
            $first->dispatch()
        );

        self::assertSame(
            500,
            $first->error()
        );

        self::assertSame(
            $exception,
            $first->exception()
        );

        self::assertTrue(
            $second->dispatch()
        );

        self::assertNull(
            $second->error()
        );

        self::assertNull(
            $second->exception()
        );

        self::assertTrue(
            $first->dispatch()
        );

        self::assertNull(
            $first->error()
        );

        self::assertNull(
            $first->exception()
        );

        self::assertSame(
            'first',
            Trace::$observed
        );
    }

    public function testHttpParametersCannotSelectMiddlewareClasses(): void
    {
        $_SERVER['REQUEST_URI'] = '/' . rawurlencode(ConstructorProbe::class);
        $_GET['middleware'] = ConstructorProbe::class;
        $router = new Router('https://example.test');
        $router->get('/{middleware}', static fn () => null, middleware: FirstMiddleware::class);
        self::assertTrue($router->dispatch());
        self::assertSame(['A BEFORE', 'A AFTER'], Trace::$events);
        self::assertSame(['middleware' => ConstructorProbe::class], $router->data());
    }
}
