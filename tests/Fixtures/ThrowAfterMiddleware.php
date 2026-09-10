<?php

declare(strict_types=1);

namespace MovesCode\Router\Tests\Fixtures;

/**
 * MovesCode | ThrowAfterMiddleware
 *
 * Provides observable behavior for routing regression tests.
 *
 * @author Djalma Martins
 * @package MovesCode\Router
 */
final class ThrowAfterMiddleware implements \MovesCode\Middleware\MiddlewareInterface
{
    public function handle(callable $next): mixed
    {
        $next();
        throw new \RuntimeException('private failure');
    }
}
