<?php
require_once 'config.php';

try {
    $pdo = new PDO($db_dsn, $db_user ? $db_user : null, $db_pass ? $db_pass : null);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
} catch (PDOException $e) {
    die('Ошибка подключения к базе данных');
}
