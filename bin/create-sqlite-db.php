<?php

declare(strict_types=1);

// Creates an SQLite database with the schema and demo data:
//   php bin/create-sqlite-db.php var/orders.sqlite

$path = $_SERVER['argv'][1] ?? null;

if (!is_string($path)) {
    fwrite(STDERR, "Usage: php bin/create-sqlite-db.php <path-to-database-file>\n");
    exit(1);
}

if (file_exists($path)) {
    fwrite(STDERR, "{$path} already exists, remove it first.\n");
    exit(1);
}

if (!is_dir(dirname($path))) {
    mkdir(dirname($path), 0777, true);
}

$pdo = new PDO('sqlite:' . $path, null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
$pdo->exec((string) file_get_contents(__DIR__ . '/../database/schema.sqlite.sql'));
$pdo->exec((string) file_get_contents(__DIR__ . '/../database/seed.sql'));

echo "Created {$path}\n";
