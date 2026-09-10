<?php

declare(strict_types=1);

namespace MovesCode\Router\Tests\Fixtures;

/**
 * MovesCode | Trace
 *
 * Provides observable behavior for routing regression tests.
 *
 * @author Djalma Martins
 * @package MovesCode\Router
 */
final class Trace
{
    public static array $events = [];
    public static mixed $result = null;
    public static mixed $observed = null;
}
