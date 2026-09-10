<?php

declare(strict_types=1);

namespace MovesCode\Router\Tests\Fixtures;

/**
 * MovesCode | BrokenMiddleware
 *
 * Provides observable behavior for routing regression tests.
 *
 * @author Djalma Martins
 * @package MovesCode\Router
 */
final class BrokenMiddleware
{
    public function unrelated(): void {}
}
