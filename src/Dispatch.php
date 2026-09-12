<?php

declare(strict_types=1);

namespace MovesCode\Router;

use Closure;
use InvalidArgumentException;
use MovesCode\Middleware\MiddlewareInterface;
use MovesCode\Middleware\Pipeline;
use MovesCode\Router\Internal\RegisteredMiddleware;
use ReflectionFunction;
use ReflectionMethod;
use Throwable;

/** @internal Immutable registered-route definition. */
final readonly class Route
{
    /**
     * @param callable|string $handler
     * @param list<string|MiddlewareInterface> $middleware
     * @param list<string> $hostParameters
     * @param list<string> $parameters
     */
    public function __construct(
        public string  $method,
        public string  $route,
        public mixed   $handler,
        public ?string $name,
        public array   $middleware,
        public ?string $namespace,
        public string  $group,
        public string  $pattern,
        public array   $parameters,
        public ?string $host,
        public ?string $hostPattern,
        public array   $hostParameters,
    ) {
    }
}

abstract class Dispatch
{
    private string $projectUrl;
    private string $basePath;
    /** @var non-empty-string */
    private string $separator;
    private string $scheme;
    private string $baseHost;
    private ?int $port;
    private ?string $host = null;
    private string $group = '';
    private ?string $namespace = null;
    /** @var list<string|MiddlewareInterface> */
    private array $groupMiddleware = [];
    /** @var array<string, list<Route>> */
    private array $routes = [];
    /** @var array<string, Route> */
    private array $named = [];
    /** @var array<string, string>|null */
    private ?array $data = null;
    /** @var \stdClass|null */
    private ?object $current = null;
    private ?int $error = null;
    private ?Throwable $exception = null;

    public function __construct(string $projectUrl, ?string $separator = ':')
    {
        $parts = parse_url($projectUrl);
        if ($parts === false || !isset($parts['scheme'], $parts['host'])) {
            throw new InvalidArgumentException('The project URL must be an absolute HTTP(S) URL.');
        }

        $scheme = strtolower((string)$parts['scheme']);
        if (!in_array($scheme, ['http', 'https'], true)) {
            throw new InvalidArgumentException('The project URL must use HTTP or HTTPS.');
        }

        $this->basePath = $this->normalizePath((string)($parts['path'] ?? ''));
        $this->scheme = $scheme;
        $this->baseHost = strtolower((string)$parts['host']);
        $this->port = isset($parts['port']) ? (int)$parts['port'] : null;
        $this->projectUrl = rtrim($projectUrl, '/');
        $this->separator = $separator ?: ':';
    }

    /** @return $this */
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

    /** @return $this */
    public function subdomain(?string $subdomain): self
    {
        if ($subdomain === null) return $this->domain(null);
        $subdomain = trim($subdomain, '.');
        return $this->domain($subdomain === '' ? $this->baseHost : $subdomain . '.' . $this->baseHost);
    }

    /** @return $this */
    public function namespace(?string $namespace): self
    {
        $this->namespace = $namespace === null || trim($namespace, '\\') === '' ? null : trim($namespace, '\\');
        return $this;
    }

    /**
     * @param array<mixed>|string|MiddlewareInterface|null $middleware
     * @return $this
     */
    public function group(?string $group, array|string|MiddlewareInterface|null $middleware = null): self
    {
        $this->group = $this->normalizePath($group ?? '');
        $this->groupMiddleware = $this->middlewareList($middleware);
        return $this;
    }

    /** @param array<string, mixed>|null $data */
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
            $value = $data[$parameter];
            if (!is_scalar($value) && $value !== null && !$value instanceof \Stringable) return null;
            $path = str_replace('{' . $parameter . '}', rawurlencode((string)$value), $path);
            unset($data[$parameter]);
        }
        $host = $route->host ?? $this->baseHost;
        foreach ($route->hostParameters as $parameter) {
            if (!array_key_exists($parameter, $data)) continue;
            $value = $data[$parameter];
            if (!is_scalar($value) && $value !== null && !$value instanceof \Stringable) return null;
            $value = strtolower((string)$value);
            if (preg_match('/^[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?$/', $value) !== 1) return null;
            $host = str_replace('{' . $parameter . '}', $value, $host);
            unset($data[$parameter]);
        }
        $origin = $this->scheme . '://' . $host . ($this->port === null ? '' : ':' . $this->port);
        $url = $origin . ($this->basePath === '/' ? '' : $this->basePath) . ($path === '/' ? '' : $path);
        return $data === [] ? $url : $url . '?' . http_build_query($data);
    }

    /** @return array<string, string>|null */
    public function data(): ?array
    {
        return $this->data;
    }

    /** @return \stdClass|null */
    public function current(): ?object
    {
        return $this->current;
    }

    public function home(): string
    {
        return $this->projectUrl;
    }

    /** @param array<string, mixed>|null $data */
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

    public function exception(): ?Throwable
    {
        return $this->exception;
    }

    public function dispatch(): bool
    {
        $this->error = null;
        $this->exception = null;
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
                    $data[$parameter] = rawurldecode((string)($hostMatches[$parameter] ?? ''));
                }
                foreach ($route->parameters as $parameter) {
                    $data[$parameter] = rawurldecode((string)($matches[$parameter] ?? ''));
                }
                $this->data = $data;
                $this->current = (object)[
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
                    return $this->runPipeline($route, $data);
                } catch (Throwable $exception) {
                    $this->exception = $exception;
                    $this->error = 500;

                    return false;
                }
            }
        }

        $this->error = $allowedForPath ? 405 : 404;
        return false;
    }

    /**
     * @param array<mixed>|string|MiddlewareInterface|null $middleware
     * @return $this
     */
    protected function add(string $method, string $path, callable|string $handler, ?string $name, array|string|MiddlewareInterface|null $middleware): self
    {
        $routePath = $this->joinPath($this->group, $path);
        preg_match_all('/\{([A-Za-z_][A-Za-z0-9_]*)\}/', $routePath, $found);
        $parameters = $found[1];
        if (count($parameters) !== count(array_unique($parameters))) {
            throw new InvalidArgumentException('Route parameter names must be unique.');
        }
        $hostParameters = [];
        $hostPattern = null;
        if ($this->host !== null) {
            preg_match_all('/\{([A-Za-z_][A-Za-z0-9_]*)\}/', $this->host, $hostFound);
            $hostParameters = $hostFound[1];
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
            $this->middlewareList([...$this->groupMiddleware, ...$this->middlewareList($middleware)]),
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

    /** @param array<string, string> $data */
    private function runHandler(callable|string $handler, ?string $namespace, array $data, bool &$handled): mixed
    {
        if (is_callable($handler)) {
            $result = $this->invoke($handler, $data);
            $handled = true;
            return $result;
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
        $callable = [$instance, $action];
        if (!is_callable($callable)) {
            $this->error = 501;
            return false;
        }
        $result = $this->invoke($callable, $data);
        $handled = true;
        return $result;
    }

    /** @param array<string, string> $data */
    private function invoke(callable $callable, array $data): mixed
    {
        $reflection = is_array($callable)
            ? new ReflectionMethod($callable[0], (string)$callable[1])
            : new ReflectionFunction(Closure::fromCallable($callable));
        $count = $reflection->getNumberOfParameters();
        $arguments = $count === 0 ? [] : [$data];
        if ($count > 1) {
            $arguments[] = $this;
        }
        return $callable(...$arguments);
    }

    /** @param array<string, string> $data */
    private function runPipeline(Route $route, array $data): bool
    {
        $pipeline = new Pipeline();
        foreach ($route->middleware as $middleware) {
            if ($middleware instanceof MiddlewareInterface) {
                $pipeline->pipe($middleware);
                continue;
            }
            $class = $middleware;
            // Resolve only when reached, preserving legacy short-circuit behavior.
            $pipeline->pipe(new RegisteredMiddleware(function (callable $next) use ($class): mixed {
                if (!class_exists($class) || !method_exists($class, 'handle')) {
                    $this->error = 501;
                    return false;
                }
                $instance = new $class();
                if ($instance instanceof MiddlewareInterface) {
                    return $instance->handle($next);
                }
                if ($instance->handle($this) !== true) {
                    return false;
                }
                return $next();
            }));
        }

        $handled = false;
        $pipeline->then(function () use ($route, $data, &$handled): mixed {
            return $this->runHandler($route->handler, $route->namespace, $data, $handled);
        });

        return $handled;
    }

    /**
     * @param array<mixed>|string|MiddlewareInterface|null $middleware
     * @return list<string|MiddlewareInterface>
     */
    private function middlewareList(array|string|MiddlewareInterface|null $middleware): array
    {
        if ($middleware === null || $middleware === '') {
            return [];
        }
        $items = is_array($middleware) ? $middleware : [$middleware];
        $result = [];
        foreach ($items as $item) {
            if (!$item instanceof MiddlewareInterface && (!is_string($item) || trim($item) === '')) {
                throw new InvalidArgumentException('Middleware must be a class-string, a MiddlewareInterface instance or a list of these values.');
            }
            if (!in_array($item, $result, true)) {
                $result[] = $item;
            }
        }
        return $result;
    }

    private function requestMethod(): string
    {
        $requestMethod = $_SERVER['REQUEST_METHOD'] ?? 'GET';
        $method = is_string($requestMethod) ? strtoupper($requestMethod) : 'GET';
        if ($method === 'POST' && isset($_POST['_method'])) {
            $spoofed = is_string($_POST['_method']) ? strtoupper($_POST['_method']) : '';
            if (in_array($spoofed, ['PUT', 'PATCH', 'DELETE'], true)) {
                return $spoofed;
            }
        }
        return $method;
    }

    private function requestPath(): string
    {
        $source = $_GET['route'] ?? $_SERVER['REQUEST_URI'] ?? '/';
        $source = is_string($source) ? $source : '/';
        $path = parse_url($source, PHP_URL_PATH);
        $path = is_string($path) ? $path : '/';
        if ($this->basePath !== '/' && str_starts_with($path . '/', $this->basePath . '/')) {
            $path = substr($path, strlen($this->basePath)) ?: '/';
        }
        return $this->normalizePath($path);
    }

    private function requestHost(): string
    {
        $requestHost = $_SERVER['HTTP_HOST'] ?? $this->baseHost;
        $host = is_string($requestHost) ? strtolower(trim($requestHost)) : $this->baseHost;
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
