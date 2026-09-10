<?php

declare(strict_types=1);

namespace MovesCode\Router\Tests;

use MovesCode\Router\Router;
use MovesCode\Router\Tests\Fixtures\{Trace, LegacyAllow, LegacyDeny, LegacyTruthy, ConstructorProbe, BrokenMiddleware, ThrowingLegacy, Controller};
use PHPUnit\Framework\TestCase;

/**
 * MovesCode | RouterRegressionTest
 *
 * Captures existing public behavior before pipeline integration.
 *
 * @author Djalma Martins
 * @package MovesCode\Router
 */
final class RouterRegressionTest extends TestCase
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

    public function testRouteWithoutMiddlewareKeepsBooleanDispatch(): void
    {
        foreach ([null, false, 0, 'done', new \stdClass()] as $result) {
            $router = new Router('https://example.test');
            $calls = 0;
            $router->get('/', static function () use (&$calls, $result): mixed { ++$calls; return $result; });
            self::assertTrue($router->dispatch());
            self::assertNull($router->error());
            self::assertSame(1, $calls);
        }
    }

    public function testLegacyGroupAndRouteOrderAndDeduplication(): void
    {
        $router = new Router('https://example.test');
        $_SERVER['REQUEST_URI'] = '/admin/42';
        $router->group('admin', LegacyAllow::class)->get('/{id}', static function (): void {
            Trace::$events[] = 'handler';
        }, middleware: [LegacyAllow::class, ConstructorProbe::class]);
        self::assertTrue($router->dispatch());
        self::assertSame(['legacy:42', 'constructed', 'handler'], Trace::$events);
    }

    public function testLegacyShortCircuitDoesNotResolveLaterMiddleware(): void
    {
        $router = new Router('https://example.test');
        $router->get('/', static function (): never { self::fail('Handler executed'); }, middleware: [LegacyDeny::class, ConstructorProbe::class, 'MissingMiddleware']);
        self::assertFalse($router->dispatch());
        self::assertNull($router->error());
        self::assertSame(['denied'], Trace::$events);
    }

    public function testLegacyRequiresStrictTrue(): void
    {
        $router = new Router('https://example.test');
        $router->get('/', static function (): never { self::fail('Handler executed'); }, middleware: LegacyTruthy::class);
        self::assertFalse($router->dispatch());
        self::assertNull($router->error());
    }

    public function testUnavailableMiddleware(): void
    {
        foreach (['MissingMiddleware', BrokenMiddleware::class] as $class) {
            $router = new Router('https://example.test');
            $router->get('/', static function (): never { self::fail('Handler executed'); }, middleware: $class);
            self::assertFalse($router->dispatch());
            self::assertSame(501, $router->error());
        }
    }

    public function testInvalidMiddlewareRegistration(): void
    {
        $router = new Router('https://example.test');
        $this->expectException(\InvalidArgumentException::class);
        $router->get('/', static fn () => null, middleware: [new \stdClass()]);
    }

    public function testLegacyExceptionUsesDocumentedError(): void
    {
        $router = new Router('https://example.test');
        $router->get('/', static fn () => null, middleware: ThrowingLegacy::class);
        self::assertFalse($router->dispatch());
        self::assertSame(500, $router->error());
    }

    public function testHandlerExceptionAndRepeatedDispatch(): void
    {
        $router = new Router('https://example.test');
        $fail = true;
        $router->get('/', static function () use (&$fail): void {
            if ($fail) { $fail = false; throw new \Error('private error'); }
        });
        self::assertFalse($router->dispatch());
        self::assertSame(500, $router->error());
        self::assertTrue($router->dispatch());
        self::assertNull($router->error());
    }

    public function testControllerNamespaceParametersAndRouterInjection(): void
    {
        $router = new Router('https://example.test');
        $_SERVER['REQUEST_URI'] = '/item/a%20b';
        $router->namespace('MovesCode\\Router\\Tests\\Fixtures')->get('/item/{id}', 'Controller:show', 'item');
        self::assertTrue($router->dispatch());
        self::assertSame([['id' => 'a b'], true], Trace::$observed);
        self::assertSame(['id' => 'a b'], $router->data());
        $current = $router->current();
        self::assertNotNull($current);
        self::assertSame('item', $current->name);
        self::assertSame('https://example.test/item/a%20b?tab=info', $router->route('item', ['id' => 'a b', 'tab' => 'info']));
    }

    public function testCallableReceivesParametersAndRouter(): void
    {
        $_SERVER['REQUEST_URI'] = '/42';
        $router = new Router('https://example.test');
        $router->get('/{id}', static function (array $data, Router $actual) use ($router): void {
            self::assertSame(['id' => '42'], $data);
            self::assertSame($router, $actual);
        });
        self::assertTrue($router->dispatch());
    }

    public function testHttpMethodsAndSpoofing(): void
    {
        foreach (['get', 'post', 'put', 'patch', 'delete'] as $method) {
            $router = new Router('https://example.test');
            $router->{$method}('/', static fn () => null);
            $_SERVER['REQUEST_METHOD'] = strtoupper($method);
            self::assertTrue($router->dispatch());
            if (in_array($method, ['put', 'patch', 'delete'], true)) {
                $_SERVER['REQUEST_METHOD'] = 'POST';
                $_POST['_method'] = strtoupper($method);
                self::assertTrue($router->dispatch());
            }
            $_POST = [];
        }
    }

    public function testDomainParametersAndNamedRoute(): void
    {
        $router = new Router('https://example.test/base');
        $_SERVER['HTTP_HOST'] = 'tenant.example.test:443';
        $_SERVER['REQUEST_URI'] = '/base/7';
        $router->domain('{tenant}.example.test')->get('/{id}', static fn () => null, 'tenant.item');
        self::assertTrue($router->dispatch());
        self::assertSame(['tenant' => 'tenant', 'id' => '7'], $router->data());
        self::assertSame('https://foo.example.test/base/8', $router->route('tenant.item', ['tenant' => 'foo', 'id' => '8']));
        self::assertNull($router->route('tenant.item', ['tenant' => 'bad/host', 'id' => '8']));
    }

    public function testGroupResetSubdomainsAndQueryRoute(): void
    {
        $router = new Router('https://example.test');
        $router->subdomain('admin')->group('admin')->get('/dashboard', static fn () => null);
        $router->subdomain(null)->group(null)->get('/public', static fn () => null, 'public');
        $_GET['route'] = '/public';
        self::assertTrue($router->dispatch());
        $current = $router->current();
        self::assertNotNull($current);
        self::assertSame('public', $current->name);
        self::assertSame('https://example.test', $router->home());
        self::assertNull($router->route('missing'));
    }

    public function testNotFoundMethodNotAllowedAndStateReset(): void
    {
        $router = new Router('https://example.test');
        $router->post('/', static fn () => null);
        self::assertFalse($router->dispatch());
        self::assertSame(405, $router->error());
        $_SERVER['REQUEST_URI'] = '/missing';
        self::assertFalse($router->dispatch());
        self::assertSame(404, $router->error());
        self::assertNull($router->data());
        self::assertNull($router->current());
    }

    public function testInvalidControllerStatuses(): void
    {
        foreach (['Missing' => 501, 'Invalid-Name:run' => 400, Controller::class . ':hidden' => 501] as $handler => $status) {
            $router = new Router('https://example.test');
            $router->get('/', $handler);
            self::assertFalse($router->dispatch());
            self::assertSame($status, $router->error());
        }
    }
    public function testMalformedRequestValuesDoNotTriggerStringConversionWarnings(): void
    {
        $_SERVER['REQUEST_METHOD'] = ['GET'];
        $_SERVER['HTTP_HOST'] = ['example.test'];
        $_GET['route'] = ['/'];
        $router = new Router('https://example.test');
        $router->get('/', static fn () => null);
        self::assertTrue($router->dispatch());
        $_SERVER['REQUEST_METHOD'] = 'POST';
        $_POST['_method'] = ['DELETE'];
        $router->post('/', static fn () => null);
        self::assertTrue($router->dispatch());
    }

    public function testNamedRouteRejectsNonStringableParameters(): void
    {
        $router = new Router('https://example.test');
        $router->domain('{tenant}.example.test')->get('/{id}', static fn () => null, 'item');
        self::assertNull($router->route('item', ['id' => [], 'tenant' => 'ok']));
        self::assertNull($router->route('item', ['id' => 1, 'tenant' => new \stdClass()]));
        self::assertSame('https://ok.example.test/1?filter%5Bactive%5D=1', $router->route('item', ['id' => 1, 'tenant' => 'ok', 'filter' => ['active' => 1]]));
    }

}
