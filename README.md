# MovesOS Router

Official, standalone routing component for MovesOS. Version 1.0.0 provides a stable routing foundation for PHP 8.2 and newer.

## Installation

```bash
composer require movescode/router:^1.0
```

Point all non-file requests to your front controller and create the router with the public base URL:

```php
use MovesCode\Router\Router;

$router = new Router('https://example.com');
$router->get('/', fn () => print 'Hello');
$router->dispatch();

if ($router->error()) {
    $router->redirect('/ops/' . $router->error());
}
```

Both `REQUEST_URI` front controllers and the traditional `?route=/path` rewrite are supported.

## HTTP methods

```php
$router->get('/posts', 'Posts:index');
$router->post('/posts', 'Posts:store');
$router->put('/posts/{id}', 'Posts:update');
$router->patch('/posts/{id}', 'Posts:patch');
$router->delete('/posts/{id}', 'Posts:delete');
```

For HTML forms using POST, add `_method` with `PUT`, `PATCH`, or `DELETE`. Spoofing is intentionally ignored for non-POST requests.

## Groups and namespaces

```php
$router
    ->group('studio')
    ->namespace('Source\\Controllers\\Studio');

$router->get('/', 'Dashboard:home');
$router->group('studio/posts');
$router->get('/{id}', 'Posts:show');
```

Calling `group('')` returns subsequent registrations to the root. A later group is an absolute group selection, not a child implicitly appended to the previous group.

## Controllers and callables

Controller classes are resolved only from handlers registered by application code. They receive the router in their constructor, and route parameters are passed to the action:

```php
final class Posts
{
    public function __construct(private Router $router) {}

    public function show(array $data): void
    {
        echo $data['id'];
    }
}
```

Callables receive route data and may optionally receive the router:

```php
$router->get('/hello/{name}', function (array $data, Router $router): void {
    echo "Hello {$data['name']}";
});
```

## Named routes

```php
$router->get('/posts/{id}/edit', 'Posts:edit', 'post.edit');

$url = $router->route('post.edit', [
    'id' => 10,
    'tab' => 'media',
]);
// https://example.com/posts/10/edit?tab=media
```

Values used by placeholders are URL encoded. Remaining values become a query string.

## Middleware

Middleware classes expose `handle(Router $router): bool`. Returning anything other than `true` stops dispatch.

```php
$router->group('studio', AuthMiddleware::class);
$router->get('/', 'Dashboard:home');
$router->get('/admin', 'Dashboard:admin', middleware: [
    AuthMiddleware::class,
    AdminMiddleware::class,
]);
```

Group middleware and route middleware are combined; duplicate class names run once.

## Redirects and errors

`redirect()` accepts an absolute URL, a path, or a named route. `error()` returns `404` when no path matches, `405` when the path exists for another method, `400` for an unsafe controller declaration, and `501` for an unavailable handler or middleware.

```php
if ($router->error()) {
    $router->redirect('/ops/' . $router->error());
}
```

Unhandled exceptions from application handlers result in error `500` without leaking exception details.

## MovesOS integration

Use `MovesCode\Router\Router` in the application front controller, register each product's routes, and call `dispatch()` once after route registration. The package is independent from application configuration, databases, templates, and local filesystem paths.

Functional controller and method-spoofing examples are available in `exemple/`.

## License

MIT. See [LICENSE](LICENSE).
