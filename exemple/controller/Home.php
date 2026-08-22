<?php

namespace Example\Controllers;

use MovesCode\Router\Router;

final class Home
{
    public function __construct(private Router $router) {}
    public function show(array $data): void { echo $data['name']; }
}
