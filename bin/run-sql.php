<?php

declare(strict_types=1);

// Runs an SQL file against the test database from TEST_DB_DSN / TEST_DB_USER / TEST_DB_PASSWORD:
//   php bin/run-sql.php database/schema.sql

$file = $_SERVER['argv'][1] ?? null;
$dsn = getenv('TEST_DB_DSN');

if (!is_string($file) || !is_file($file) || $dsn === false) {
    fwrite(STDERR, "Usage: TEST_DB_DSN=... php bin/run-sql.php <file.sql>\n");
    exit(1);
}

$pdo = new PDO($dsn, getenv('TEST_DB_USER') ?: null, getenv('TEST_DB_PASSWORD') ?: null, [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
]);
$pdo->exec((string) file_get_contents($file));

echo "Applied {$file}\n";
