<?php

declare(strict_types=1);

namespace MovesCode\Router\Tests\Fixtures;

/**
 * MovesCode | ThrowBeforeMiddleware
 *
 * Provides observable behavior for routing regression tests.
 *
 * @author Djalma Martins
 * @package MovesCode\Router
 */
final class ThrowBeforeMiddleware implements \MovesCode\Middleware\MiddlewareInterface
{
    public function handle(callable $next): mixed { throw new \RuntimeException('private failure'); }
}
