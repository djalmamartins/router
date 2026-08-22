<?php

declare(strict_types=1);

namespace MovesCode\Router;

trait RouterTrait
{
    public function get(string $route, callable|string $handler, ?string $name = null, array|string|null $middleware = null): self
    {
        return $this->add('GET', $route, $handler, $name, $middleware);
    }

    public function post(string $route, callable|string $handler, ?string $name = null, array|string|null $middleware = null): self
    {
        return $this->add('POST', $route, $handler, $name, $middleware);
    }

    public function put(string $route, callable|string $handler, ?string $name = null, array|string|null $middleware = null): self
    {
        return $this->add('PUT', $route, $handler, $name, $middleware);
    }

    public function patch(string $route, callable|string $handler, ?string $name = null, array|string|null $middleware = null): self
    {
        return $this->add('PATCH', $route, $handler, $name, $middleware);
    }

    public function delete(string $route, callable|string $handler, ?string $name = null, array|string|null $middleware = null): self
    {
        return $this->add('DELETE', $route, $handler, $name, $middleware);
    }
}
