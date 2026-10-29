<?php

declare(strict_types=1);

/** @var App\Http\OrderController $controller */
$controller = require __DIR__ . '/../bootstrap.php';

$id = filter_var($_GET['id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]) ?: 0;

$response = $_SERVER['REQUEST_METHOD'] === 'POST'
    ? $controller->handleOrderAction($id, $_POST)
    : $controller->showOrder($id, $_GET);

$response->send();
