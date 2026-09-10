<?php

declare(strict_types=1);

namespace MovesCode\Router\Tests\Fixtures;

/**
 * MovesCode | SecondMiddleware
 *
 * Provides observable behavior for routing regression tests.
 *
 * @author Djalma Martins
 * @package MovesCode\Router
 */
final class SecondMiddleware implements \MovesCode\Middleware\MiddlewareInterface
{
    public function handle(callable $next): mixed
    {
        Trace::$events[] = 'B BEFORE';
        $result = $next();
        Trace::$observed = $result;
        Trace::$events[] = 'B AFTER';
        return $result;
    }
}
