<?php

declare(strict_types=1);

namespace MovesCode\Router\Tests\Fixtures;

/**
 * MovesCode | ShortCircuitMiddleware
 *
 * Provides observable behavior for routing regression tests.
 *
 * @author Djalma Martins
 * @package MovesCode\Router
 */
final class ShortCircuitMiddleware implements \MovesCode\Middleware\MiddlewareInterface
{
    public function handle(callable $next): mixed
    {
        Trace::$events[] = 'short';
        return Trace::$result;
    }
}
