<?php

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use MovesCode\Router\Router;

$router = new Router('https://moves.com.br');

$router->subdomain('')->get('/', fn () => print 'Moves Web', 'web.home');
$router->subdomain('studio')->get('/', fn () => print 'Moves Studio', 'studio.home');
$router->subdomain('app')->get('/', fn () => print 'Moves App', 'app.home');
$router->subdomain('api')->get('/status', function (): void {
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['status' => 'ok'], JSON_THROW_ON_ERROR);
}, 'api.status');

$router->dispatch();
