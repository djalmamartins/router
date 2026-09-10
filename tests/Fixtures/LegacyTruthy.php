<?php

declare(strict_types=1);

namespace MovesCode\Router\Tests\Fixtures;

/**
 * MovesCode | LegacyTruthy
 *
 * Provides observable behavior for routing regression tests.
 *
 * @author Djalma Martins
 * @package MovesCode\Router
 */
final class LegacyTruthy
{
    public function handle(\MovesCode\Router\Router $router): int { return 1; }
}
