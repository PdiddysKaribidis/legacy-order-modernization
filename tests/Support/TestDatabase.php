<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Database;
use PDO;

/**
 * SQLite in memory by default. Set TEST_DB_DSN (and TEST_DB_USER, TEST_DB_PASSWORD)
 * to run the same tests against MySQL/MariaDB with database/schema.sql already applied.
 */
final class TestDatabase
{
    public static function connect(): PDO
    {
        $dsn = getenv('TEST_DB_DSN') ?: 'sqlite::memory:';
        $pdo = Database::connect($dsn, getenv('TEST_DB_USER') ?: null, getenv('TEST_DB_PASSWORD') ?: null);

        if (str_starts_with($dsn, 'sqlite:')) {
            $pdo->exec((string) file_get_contents(__DIR__ . '/../../database/schema.sqlite.sql'));
        } else {
            $pdo->exec('DELETE FROM order_items');
            $pdo->exec('DELETE FROM orders');
        }

        return $pdo;
    }

    public static function createSqliteFile(string $path): void
    {
        $pdo = Database::connect('sqlite:' . $path);
        $pdo->exec((string) file_get_contents(__DIR__ . '/../../database/schema.sqlite.sql'));
    }
}
