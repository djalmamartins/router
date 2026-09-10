<?php

declare(strict_types=1);

namespace MovesCode\Router\Tests\Fixtures;

/**
 * MovesCode | Controller
 *
 * Provides observable behavior for routing regression tests.
 *
 * @author Djalma Martins
 * @package MovesCode\Router
 */
final class Controller
{
    public function __construct(private \MovesCode\Router\Router $router) {}
    public function show(array $data, \MovesCode\Router\Router $router): mixed
    {
        Trace::$observed = [$data, $router === $this->router];
        Trace::$events[] = 'controller';
        return Trace::$result;
    }
    private function hidden(): void {}
}
