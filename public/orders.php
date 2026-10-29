<?php

declare(strict_types=1);

/** @var App\Http\OrderController $controller */
$controller = require __DIR__ . '/../bootstrap.php';

$response = $_SERVER['REQUEST_METHOD'] === 'POST'
    ? $controller->createOrder($_POST)
    : $controller->listOrders($_GET);

$response->send();
