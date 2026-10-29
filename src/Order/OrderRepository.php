<?php

declare(strict_types=1);

namespace App\Order;

use DateTimeImmutable;
use PDO;

/**
 * All SQL of the orders module. Works on MySQL/MariaDB and SQLite.
 */
final class OrderRepository
{
    public function __construct(private readonly PDO $pdo)
    {
    }

    public function get(int $id): Order
    {
        $stmt = $this->pdo->prepare('SELECT * FROM orders WHERE id = ?');
        $stmt->execute([$id]);
        $row = $stmt->fetch();

        if ($row === false) {
            throw OrderNotFoundException::withId($id);
        }

        return $this->hydrate($row, $this->loadItems([$id])[$id] ?? []);
    }

    /**
     * Two queries for any number of orders; the legacy list page ran one query per order.
     *
     * @return list<Order>
     */
    public function findAll(?OrderStatus $status = null): array
    {
        $where = $status === null ? '' : 'WHERE status = ?';
        $stmt = $this->pdo->prepare("SELECT * FROM orders {$where} ORDER BY id DESC");
        $stmt->execute($status === null ? [] : [$status->value]);
        $rows = $stmt->fetchAll();

        $ids = [];
        foreach ($rows as $row) {
            $ids[] = (int) $row['id'];
        }
        $items = $this->loadItems($ids);

        $orders = [];
        foreach ($rows as $row) {
            $orders[] = $this->hydrate($row, $items[(int) $row['id']] ?? []);
        }

        return $orders;
    }

    public function create(string $customerName, string $customerEmail, DateTimeImmutable $createdAt): int
    {
        $stmt = $this->pdo->prepare(
            'INSERT INTO orders (customer_name, customer_email, status, created_at) VALUES (?, ?, ?, ?)'
        );
        $stmt->execute([$customerName, $customerEmail, OrderStatus::New->value, $createdAt->format('Y-m-d H:i:s')]);

        return (int) $this->pdo->lastInsertId();
    }

    /**
     * Inserts the item only if the order is still new at the moment of the insert.
     * Returns false if another request has changed the status since the order was loaded.
     */
    public function addItemToNewOrder(int $orderId, OrderItem $item): bool
    {
        $stmt = $this->pdo->prepare(
            'INSERT INTO order_items (order_id, product_name, quantity, price)
             SELECT id, ?, ?, ? FROM orders WHERE id = ? AND status = ?'
        );
        $stmt->execute([
            $item->productName,
            $item->quantity,
            Money::toDecimal($item->price),
            $orderId,
            OrderStatus::New->value,
        ]);

        return $stmt->rowCount() === 1;
    }

    /**
     * Optimistic check: the update applies only if the status is still the one the decision was based on.
     */
    public function updateStatus(int $orderId, OrderStatus $from, OrderStatus $to): bool
    {
        $stmt = $this->pdo->prepare('UPDATE orders SET status = ? WHERE id = ? AND status = ?');
        $stmt->execute([$to->value, $orderId, $from->value]);

        return $stmt->rowCount() === 1;
    }

    /**
     * @param list<int> $orderIds
     * @return array<int, list<OrderItem>>
     */
    private function loadItems(array $orderIds): array
    {
        if ($orderIds === []) {
            return [];
        }

        $placeholders = implode(', ', array_fill(0, count($orderIds), '?'));
        $stmt = $this->pdo->prepare(
            "SELECT order_id, product_name, quantity, price FROM order_items WHERE order_id IN ({$placeholders}) ORDER BY id"
        );
        $stmt->execute($orderIds);

        $items = [];
        foreach ($stmt->fetchAll() as $row) {
            $items[(int) $row['order_id']][] = new OrderItem(
                $row['product_name'],
                (int) $row['quantity'],
                Money::fromDecimal($row['price']),
            );
        }

        return $items;
    }

    /**
     * @param array<string, mixed> $row
     * @param list<OrderItem> $items
     */
    private function hydrate(array $row, array $items): Order
    {
        return new Order(
            (int) $row['id'],
            $row['customer_name'],
            $row['customer_email'],
            OrderStatus::from($row['status']),
            $items,
            new DateTimeImmutable($row['created_at']),
        );
    }
}
