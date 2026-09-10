<?php

declare(strict_types=1);

namespace MovesCode\Router\Internal;

use Closure;
use MovesCode\Middleware\MiddlewareInterface;

/**
 * MovesCode | RegisteredMiddleware
 *
 * Adapts lazy route middleware resolution to the external pipeline contract.
 *
 * @author Djalma Martins
 * @package MovesCode\Router
 * @internal
 */
final class RegisteredMiddleware implements MiddlewareInterface
{
    /** @param Closure(callable): mixed $handler */
    public function __construct(private readonly Closure $handler)
    {
    }

    public function handle(callable $next): mixed
    {
        return ($this->handler)($next);
    }
}
