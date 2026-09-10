<?php

declare(strict_types=1);

namespace MovesCode\Router\Tests\Fixtures;

/**
 * MovesCode | ConstructorProbe
 *
 * Provides observable behavior for routing regression tests.
 *
 * @author Djalma Martins
 * @package MovesCode\Router
 */
final class ConstructorProbe
{
    public function __construct() { Trace::$events[] = 'constructed'; }
    public function handle(\MovesCode\Router\Router $router): bool { return true; }
}
