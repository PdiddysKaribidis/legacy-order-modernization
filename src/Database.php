<?php

declare(strict_types=1);

namespace App;

use PDO;

final class Database
{
    public static function connect(string $dsn, ?string $user = null, ?string $password = null): PDO
    {
        $pdo = new PDO($dsn, $user, $password, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            // Real prepared statements on MySQL instead of client-side emulation.
            PDO::ATTR_EMULATE_PREPARES => false,
        ]);

        if (str_starts_with($dsn, 'sqlite:')) {
            $pdo->exec('PRAGMA foreign_keys = ON');
        }

        return $pdo;
    }
}
