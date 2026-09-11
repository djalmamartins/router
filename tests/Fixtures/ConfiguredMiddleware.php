<?php

declare(strict_types=1);

namespace MovesCode\Router\Tests\Fixtures;

use MovesCode\Middleware\MiddlewareInterface;

/**
 * MovesCode | ConfiguredMiddleware
 *
 * Records configured instance execution and identity across dispatches.
 *
 * @author Djalma Martins
 * @package MovesCode\Router
 */
final class ConfiguredMiddleware implements MiddlewareInterface
{
    public int $calls = 0;

    public function __construct(private readonly string $permission)
    {
    }

    public function handle(callable $next): mixed
    {
        ++$this->calls;
        Trace::$events[] = $this->permission . ' BEFORE';
        $result = $next();
        Trace::$events[] = $this->permission . ' AFTER';
        return $result;
    }
}
