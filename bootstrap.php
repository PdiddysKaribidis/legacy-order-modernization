<?php

declare(strict_types=1);

use App\Database;
use App\Http\Csrf;
use App\Http\OrderController;
use App\Http\Response;
use App\Http\View;
use App\Order\OrderRepository;
use App\Order\OrderService;

require __DIR__ . '/vendor/autoload.php';

// Builds the objects for public/*.php by hand: with one controller and a few services
// a DI container would add more code than it removes.

$view = new View(__DIR__ . '/templates');

set_exception_handler(function (Throwable $e) use ($view): void {
    error_log((string) $e);
    (new Response(500, $view->render('error', ['message' => 'Внутренняя ошибка. Попробуйте позже.'])))->send();
});

try {
    $pdo = Database::connect(
        getenv('DB_DSN') ?: 'mysql:host=127.0.0.1;dbname=orders;charset=utf8mb4',
        getenv('DB_USER') ?: null,
        getenv('DB_PASSWORD') ?: null,
    );
} catch (PDOException $e) {
    error_log('Database connection failed: ' . $e->getMessage());
    (new Response(503, $view->render('error', ['message' => 'База данных недоступна. Попробуйте позже.'])))->send();
    exit;
}

// The pages have one-click actions ("Cancel order"), so they must not be framed by other sites.
header('X-Frame-Options: DENY');

session_start(['cookie_httponly' => true, 'cookie_samesite' => 'Lax', 'use_strict_mode' => true]);

$orders = new OrderRepository($pdo);

return new OrderController($orders, new OrderService($orders), $view, new Csrf($_SESSION));
