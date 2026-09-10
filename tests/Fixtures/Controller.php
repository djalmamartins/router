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
    /** @param array<string, string> $data */
    public function show(array $data, \MovesCode\Router\Router $router): mixed
    {
        Trace::$observed = [$data, $router === $this->router];
        Trace::$events[] = 'controller';
        return Trace::$result;
    }
    // Intentionally inaccessible action used to verify controller validation.
    // @phpstan-ignore method.unused
    private function hidden(): void {}
}
