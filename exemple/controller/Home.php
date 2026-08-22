<?php

namespace Example\Controllers;

use MovesOS\Router\Router;

final class Home
{
    public function __construct(private Router $router) {}
    public function show(array $data): void { echo $data['name']; }
}
