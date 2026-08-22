<?php

require dirname(__DIR__, 2) . '/vendor/autoload.php';
require __DIR__ . '/Home.php';

use MovesCode\Router\Router;

$router = new Router('http://localhost');
$router->namespace('Example\\Controllers')->get('/hello/{name}', 'Home:show');
$router->dispatch();
