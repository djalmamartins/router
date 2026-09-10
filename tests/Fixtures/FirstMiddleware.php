<?php

declare(strict_types=1);

namespace MovesCode\Router\Tests\Fixtures;

/**
 * MovesCode | FirstMiddleware
 *
 * Provides observable behavior for routing regression tests.
 *
 * @author Djalma Martins
 * @package MovesCode\Router
 */
final class FirstMiddleware implements \MovesCode\Middleware\MiddlewareInterface
{
    public function handle(callable $next): mixed
    {
        Trace::$events[] = 'A BEFORE';
        $result = $next();
        Trace::$observed = $result;
        Trace::$events[] = 'A AFTER';
        return $result;
    }
}
