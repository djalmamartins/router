<?php

declare(strict_types=1);

namespace MovesCode\Router;

use Closure;
use InvalidArgumentException;
use ReflectionFunction;
use ReflectionMethod;
use Throwable;

/** @internal Immutable registered-route definition. */
final readonly class Route
{
    /**
     * @param callable|string $handler
     * @param list<class-string> $middleware
     * @param list<string> $parameters
     */
    public function __construct(
        public string $method,
        public string $route,
        public mixed $handler,
        public ?string $name,
        public array $middleware,
        public ?string $namespace,
        public string $group,
        public string $pattern,
        public array $parameters,
        public ?string $host,
        public ?string $hostPattern,
        public array $hostParameters,
    ) {
    }
}

abstract class Dispatch
{
    private string $projectUrl;
    private string $basePath;
    private string $separator;
    private string $scheme;
    private string $baseHost;
    private ?int $port;
    private ?string $host = null;
    private string $group = '';
    private ?string $namespace = null;
    /** @var list<class-string> */
    private array $groupMiddleware = [];
    /** @var array<string, list<Route>> */
    private array $routes = [];
    /** @var array<string, Route> */
    private array $named = [];
    private ?array $data = null;
    private ?object $current = null;
    private ?int $error = null;

    public function __construct(string $projectUrl, ?string $separator = ':')
    {
        $parts = parse_url($projectUrl);
        if ($parts === false || !isset($parts['scheme'], $parts['host'])) {
            throw new InvalidArgumentException('The project URL must be an absolute HTTP(S) URL.');
        }

        $scheme = strtolower((string) $parts['scheme']);
        if (!in_array($scheme, ['http', 'https'], true)) {
            throw new InvalidArgumentException('The project URL must use HTTP or HTTPS.');
        }

        $this->basePath = $this->normalizePath((string) ($parts['path'] ?? ''));
        $this->scheme = $scheme;
        $this->baseHost = strtolower((string) $parts['host']);
        $this->port = isset($parts['port']) ? (int) $parts['port'] : null;
        $this->projectUrl = rtrim($projectUrl, '/');
        $this->separator = $separator ?: ':';
    }

    public function domain(?string $domain): self
    {
        if ($domain === null) {
            $this->host = null;
            return $this;
        }
        $domain = strtolower(trim($domain, " .\t\n\r\0\x0B"));
        if ($domain === '' || preg_match('/^(?=.{1,253}$)(?:\{[A-Za-z_][A-Za-z0-9_]*\}|[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?)(?:\.(?:\{[A-Za-z_][A-Za-z0-9_]*\}|[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?))*$/', $domain) !== 1) {
            throw new InvalidArgumentException('The domain must be a valid hostname without scheme, port or path.');
        }
        $this->host = $domain;
        return $this;
    }

    public function subdomain(?string $subdomain): self
    {
        if ($subdomain === null) return $this->domain(null);
        $subdomain = trim($subdomain, '.');
        return $this->domain($subdomain === '' ? $this->baseHost : $subdomain . '.' . $this->baseHost);
    }

    public function namespace(?string $namespace): self
    {
        $this->namespace = $namespace === null || trim($namespace, '\\') === '' ? null : trim($namespace, '\\');
        return $this;
    }

    public function group(?string $group, array|string|null $middleware = null): self
    {
        $this->group = $this->normalizePath($group ?? '');
        $this->groupMiddleware = $this->middlewareList($middleware);
        return $this;
    }

    public function route(string $name, ?array $data = null): ?string
    {
        $route = $this->named[$name] ?? null;
        if ($route === null) {
            return null;
        }

        $data ??= [];
        $path = $route->route;
        foreach ($route->parameters as $parameter) {
            if (!array_key_exists($parameter, $data)) {
                continue;
            }
            $path = str_replace('{' . $parameter . '}', rawurlencode((string) $data[$parameter]), $path);
            unset($data[$parameter]);
        }
        $host = $route->host ?? $this->baseHost;
        foreach ($route->hostParameters as $parameter) {
            if (!array_key_exists($parameter, $data)) continue;
            $value = strtolower((string) $data[$parameter]);
            if (preg_match('/^[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?$/', $value) !== 1) return null;
            $host = str_replace('{' . $parameter . '}', $value, $host);
            unset($data[$parameter]);
        }
        $origin = $this->scheme . '://' . $host . ($this->port === null ? '' : ':' . $this->port);
        $url = $origin . ($this->basePath === '/' ? '' : $this->basePath) . ($path === '/' ? '' : $path);
        return $data === [] ? $url : $url . '?' . http_build_query($data);
    }

    public function data(): ?array
    {
        return $this->data;
    }

    public function current(): ?object
    {
        return $this->current;
    }

    public function home(): string
    {
        return $this->projectUrl;
    }

    public function redirect(string $route, ?array $data = null): void
    {
        $target = $this->route($route, $data);
        if ($target === null) {
            $target = preg_match('~^https?://~i', $route)
                ? $route
                : $this->projectUrl . ($this->normalizePath($route) === '/' ? '' : $this->normalizePath($route));
            if ($data) {
                $target .= '?' . http_build_query($data);
            }
        }

        header('Location: ' . $target);
    }

    public function error(): ?int
    {
        return $this->error;
    }

    public function dispatch(): bool
    {
        $this->error = null;
        $this->current = null;
        $this->data = null;

        $method = $this->requestMethod();
        $path = $this->requestPath();
        $host = $this->requestHost();
        $allowedForPath = false;

        foreach ($this->routes as $registeredMethod => $routes) {
            foreach ($routes as $route) {
                $hostMatches = [];
                if ($route->hostPattern !== null && preg_match($route->hostPattern, $host, $hostMatches) !== 1) {
                    continue;
                }
                $matches = [];
                if (preg_match($route->pattern, $path, $matches) !== 1) {
                    continue;
                }
                if ($registeredMethod !== $method) {
                    $allowedForPath = true;
                    continue;
                }

                $data = [];
                foreach ($route->hostParameters as $parameter) {
                    $data[$parameter] = rawurldecode((string) ($hostMatches[$parameter] ?? ''));
                }
                foreach ($route->parameters as $parameter) {
                    $data[$parameter] = rawurldecode((string) ($matches[$parameter] ?? ''));
                }
                $this->data = $data;
                $this->current = (object) [
                    'method' => $route->method,
                    'route' => $route->route,
                    'controller' => $route->handler,
                    'name' => $route->name,
                    'middleware' => $route->middleware,
                    'group' => $route->group,
                    'namespace' => $route->namespace,
                    'host' => $host,
                    'domain' => $route->host,
                ];

                try {
                    if (!$this->runMiddleware($route->middleware)) {
                        return false;
                    }
                    return $this->runHandler($route->handler, $route->namespace, $data);
                } catch (Throwable) {
                    $this->error = 500;
                    return false;
                }
            }
        }

        $this->error = $allowedForPath ? 405 : 404;
        return false;
    }

    protected function add(string $method, string $path, callable|string $handler, ?string $name, array|string|null $middleware): self
    {
        $routePath = $this->joinPath($this->group, $path);
        preg_match_all('/\{([A-Za-z_][A-Za-z0-9_]*)\}/', $routePath, $found);
        $parameters = $found[1] ?? [];
        if (count($parameters) !== count(array_unique($parameters))) {
            throw new InvalidArgumentException('Route parameter names must be unique.');
        }
        $hostParameters = [];
        $hostPattern = null;
        if ($this->host !== null) {
            preg_match_all('/\{([A-Za-z_][A-Za-z0-9_]*)\}/', $this->host, $hostFound);
            $hostParameters = $hostFound[1] ?? [];
            if (count($hostParameters) !== count(array_unique($hostParameters)) || array_intersect($parameters, $hostParameters) !== []) {
                throw new InvalidArgumentException('Route and domain parameter names must be unique.');
            }
            $quotedHost = preg_quote($this->host, '~');
            foreach ($hostParameters as $parameter) $quotedHost = str_replace('\\{' . $parameter . '\\}', '(?P<' . $parameter . '>[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?)', $quotedHost);
            $hostPattern = '~^' . $quotedHost . '$~iD';
        }

        $quoted = preg_quote($routePath, '~');
        foreach ($parameters as $parameter) {
            $quoted = str_replace('\\{' . $parameter . '\\}', '(?P<' . $parameter . '>[^/]+)', $quoted);
        }
        $definition = new Route(
            $method,
            $routePath,
            $handler,
            $name,
            array_values(array_unique([...$this->groupMiddleware, ...$this->middlewareList($middleware)])),
            $this->namespace,
            $this->group,
            '~^' . ($routePath === '/' ? '/' : rtrim($quoted, '/')) . '/?$~uD',
            $parameters,
            $this->host,
            $hostPattern,
            $hostParameters,
        );
        $this->routes[$method][] = $definition;
        if ($name !== null && $name !== '') {
            $this->named[$name] = $definition;
        }
        return $this;
    }

    private function runHandler(callable|string $handler, ?string $namespace, array $data): bool
    {
        if (is_callable($handler)) {
            $this->invoke($handler, $data);
            return true;
        }
        if (!str_contains($handler, $this->separator)) {
            $this->error = 501;
            return false;
        }
        [$controller, $action] = explode($this->separator, $handler, 2);
        if (!preg_match('/^[A-Za-z_][A-Za-z0-9_\\\\]*$/', $controller) || !preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/', $action)) {
            $this->error = 400;
            return false;
        }
        $class = $namespace ? $namespace . '\\' . ltrim($controller, '\\') : ltrim($controller, '\\');
        if (!class_exists($class) || !method_exists($class, $action) || !(new ReflectionMethod($class, $action))->isPublic()) {
            $this->error = 501;
            return false;
        }
        $instance = new $class($this);
        $this->invoke([$instance, $action], $data);
        return true;
    }

    private function invoke(callable $callable, array $data): void
    {
        $reflection = is_array($callable)
            ? new ReflectionMethod($callable[0], (string) $callable[1])
            : new ReflectionFunction(Closure::fromCallable($callable));
        $count = $reflection->getNumberOfParameters();
        $arguments = $count === 0 ? [] : [$data];
        if ($count > 1) {
            $arguments[] = $this;
        }
        $callable(...$arguments);
    }

    /** @param list<class-string> $middleware */
    private function runMiddleware(array $middleware): bool
    {
        foreach ($middleware as $class) {
            if (!class_exists($class) || !method_exists($class, 'handle')) {
                $this->error = 501;
                return false;
            }
            $instance = new $class();
            if ($instance->handle($this) !== true) {
                return false;
            }
        }
        return true;
    }

    /** @return list<class-string> */
    private function middlewareList(array|string|null $middleware): array
    {
        if ($middleware === null || $middleware === '') {
            return [];
        }
        $items = is_array($middleware) ? $middleware : [$middleware];
        foreach ($items as $item) {
            if (!is_string($item) || trim($item) === '') {
                throw new InvalidArgumentException('Middleware must be a class-string or a list of class-strings.');
            }
        }
        return array_values($items);
    }

    private function requestMethod(): string
    {
        $method = strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET'));
        if ($method === 'POST' && isset($_POST['_method'])) {
            $spoofed = strtoupper((string) $_POST['_method']);
            if (in_array($spoofed, ['PUT', 'PATCH', 'DELETE'], true)) {
                return $spoofed;
            }
        }
        return $method;
    }

    private function requestPath(): string
    {
        $source = isset($_GET['route']) ? (string) $_GET['route'] : (string) ($_SERVER['REQUEST_URI'] ?? '/');
        $path = parse_url($source, PHP_URL_PATH);
        $path = is_string($path) ? $path : '/';
        if ($this->basePath !== '/' && str_starts_with($path . '/', $this->basePath . '/')) {
            $path = substr($path, strlen($this->basePath)) ?: '/';
        }
        return $this->normalizePath($path);
    }

    private function requestHost(): string
    {
        $host = strtolower(trim((string) ($_SERVER['HTTP_HOST'] ?? $this->baseHost)));
        if (str_starts_with($host, '[')) return trim(explode(']', $host, 2)[0], '[]');
        return explode(':', $host, 2)[0];
    }

    private function normalizePath(string $path): string
    {
        $path = '/' . trim($path, '/');
        return $path === '/' ? '/' : rtrim($path, '/');
    }

    private function joinPath(string $group, string $path): string
    {
        $joined = trim($group, '/') . '/' . trim($path, '/');
        return $this->normalizePath($joined);
    }
}
