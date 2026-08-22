<?php

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use MovesCode\Router\Router;

$router = new Router('http://localhost');

foreach (['get', 'post', 'put', 'patch', 'delete'] as $method) {
    $router->{$method}('/', static function (array $data) use ($method): void {
        echo strtoupper($method), ':', json_encode($data, JSON_THROW_ON_ERROR);
    });
}

$router->dispatch();
