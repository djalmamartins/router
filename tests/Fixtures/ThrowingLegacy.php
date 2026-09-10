<?php

declare(strict_types=1);

namespace MovesCode\Router\Tests\Fixtures;

/**
 * MovesCode | ThrowingLegacy
 *
 * Provides observable behavior for routing regression tests.
 *
 * @author Djalma Martins
 * @package MovesCode\Router
 */
final class ThrowingLegacy
{
    public function handle(\MovesCode\Router\Router $router): bool { throw new \RuntimeException('private error'); }
}
