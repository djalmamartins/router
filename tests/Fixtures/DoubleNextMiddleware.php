<?php

declare(strict_types=1);

namespace MovesCode\Router\Tests\Fixtures;

/**
 * MovesCode | DoubleNextMiddleware
 *
 * Provides observable behavior for routing regression tests.
 *
 * @author Djalma Martins
 * @package MovesCode\Router
 */
final class DoubleNextMiddleware implements \MovesCode\Middleware\MiddlewareInterface
{
    public function handle(callable $next): mixed
    {
        $next();
        return $next();
    }
}
