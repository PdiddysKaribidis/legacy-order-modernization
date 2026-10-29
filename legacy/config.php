<?php
// Настройки подключения к базе

$db_dsn = getenv('DB_DSN') ? getenv('DB_DSN') : 'mysql:host=127.0.0.1;dbname=orders;charset=utf8mb4';
$db_user = getenv('DB_USER');
$db_pass = getenv('DB_PASSWORD');
