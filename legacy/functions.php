<?php

function h($str)
{
    return htmlspecialchars($str, ENT_QUOTES, 'UTF-8');
}

function get_order($id)
{
    global $pdo;

    $stmt = $pdo->prepare('SELECT * FROM orders WHERE id = ?');
    $stmt->execute(array($id));

    return $stmt->fetch(PDO::FETCH_ASSOC);
}

function get_order_items($order_id)
{
    global $pdo;

    $stmt = $pdo->prepare('SELECT * FROM order_items WHERE order_id = ? ORDER BY id');
    $stmt->execute(array($order_id));

    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

function redirect($url)
{
    header('Location: ' . $url);
    exit;
}
