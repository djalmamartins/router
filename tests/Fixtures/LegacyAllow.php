<?php

declare(strict_types=1);

namespace MovesCode\Router\Tests\Fixtures;

/**
 * MovesCode | LegacyAllow
 *
 * Provides observable behavior for routing regression tests.
 *
 * @author Djalma Martins
 * @package MovesCode\Router
 */
final class LegacyAllow
{
    public function handle(\MovesCode\Router\Router $router): bool
    {
        Trace::$events[] = 'legacy:' . ($router->data()['id'] ?? 'ok');
        return true;
    }
}
