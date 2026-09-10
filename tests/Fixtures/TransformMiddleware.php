<?php

declare(strict_types=1);

namespace MovesCode\Router\Tests\Fixtures;

/**
 * MovesCode | TransformMiddleware
 *
 * Provides observable behavior for routing regression tests.
 *
 * @author Djalma Martins
 * @package MovesCode\Router
 */
final class TransformMiddleware implements \MovesCode\Middleware\MiddlewareInterface
{
    public function handle(callable $next): mixed
    {
        return ['wrapped' => $next()];
    }
}
