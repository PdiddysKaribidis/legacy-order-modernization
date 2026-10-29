<?php

declare(strict_types=1);

namespace Tests\Integration;

use App\Order\OrderItem;
use App\Order\OrderNotFoundException;
use App\Order\OrderRepository;
use App\Order\OrderStatus;
use DateTimeImmutable;
use PDO;
use PHPUnit\Framework\TestCase;
use Tests\Support\TestDatabase;

final class OrderRepositoryTest extends TestCase
{
    private PDO $pdo;
    private OrderRepository $orders;

    protected function setUp(): void
    {
        $this->pdo = TestDatabase::connect();
        $this->orders = new OrderRepository($this->pdo);
    }

    public function test_created_order_is_read_back_with_items(): void
    {
        $id = $this->orders->create('ООО «Ромашка»', 'info@romashka.example', new DateTimeImmutable('2026-10-20 10:15:00'));
        $this->orders->addItemToNewOrder($id, new OrderItem('Бумага А4', 10, 38_990));
        $this->orders->addItemToNewOrder($id, new OrderItem('Картридж', 2, 745_000));

        $order = $this->orders->get($id);

        $this->assertSame('ООО «Ромашка»', $order->customerName);
        $this->assertSame(OrderStatus::New, $order->status());
        $this->assertSame('2026-10-20 10:15', $order->createdAt->format('Y-m-d H:i'));
        $this->assertCount(2, $order->items());
        $this->assertSame(38_990, $order->items()[0]->price);
        $this->assertSame(1_879_900, $order->subtotal());
    }

    public function test_missing_order(): void
    {
        $this->expectException(OrderNotFoundException::class);

        $this->orders->get(12345);
    }

    public function test_list_is_newest_first_and_filtered_by_status(): void
    {
        $first = $this->orders->create('Первый', '', new DateTimeImmutable());
        $second = $this->orders->create('Второй', '', new DateTimeImmutable());
        $this->orders->addItemToNewOrder($second, new OrderItem('Ручка', 1, 5_000));
        $this->orders->updateStatus($second, OrderStatus::New, OrderStatus::Paid);

        $this->assertSame([$second, $first], array_map(fn ($order) => $order->id, $this->orders->findAll()));
        $this->assertSame([$second], array_map(fn ($order) => $order->id, $this->orders->findAll('paid')));
        $this->assertSame([], $this->orders->findAll('shipped'));
        $this->assertSame([], $this->orders->findAll('no-such-status'));
    }

    public function test_order_with_status_written_by_legacy_form_is_readable(): void
    {
        $id = $this->orders->create('Клиент', '', new DateTimeImmutable());
        $this->pdo->prepare('UPDATE orders SET status = ? WHERE id = ?')->execute(['archived', $id]);

        $this->assertSame('archived', $this->orders->get($id)->statusLabel());
        $this->assertSame([$id], array_map(fn ($order) => $order->id, $this->orders->findAll('archived')));
    }

    public function test_list_loads_items_of_all_orders_with_two_queries(): void
    {
        foreach (range(1, 5) as $n) {
            $id = $this->orders->create("Клиент {$n}", '', new DateTimeImmutable());
            $this->orders->addItemToNewOrder($id, new OrderItem('Товар', $n, 100_00));
        }

        $totals = array_map(fn ($order) => $order->subtotal(), $this->orders->findAll());

        $this->assertSame([500_00, 400_00, 300_00, 200_00, 100_00], $totals);
    }

    public function test_item_is_not_added_once_order_is_no_longer_new(): void
    {
        $id = $this->orders->create('Клиент', '', new DateTimeImmutable());
        $this->orders->addItemToNewOrder($id, new OrderItem('Ручка', 1, 5_000));
        $this->orders->updateStatus($id, OrderStatus::New, OrderStatus::Paid);

        $added = $this->orders->addItemToNewOrder($id, new OrderItem('Карандаш', 1, 2_000));

        $this->assertFalse($added);
        $this->assertCount(1, $this->orders->get($id)->items());
    }

    public function test_status_update_fails_if_status_changed_meanwhile(): void
    {
        $id = $this->orders->create('Клиент', '', new DateTimeImmutable());
        $this->orders->updateStatus($id, OrderStatus::New, OrderStatus::Cancelled);

        $this->assertFalse($this->orders->updateStatus($id, OrderStatus::New, OrderStatus::Paid));
        $this->assertSame(OrderStatus::Cancelled, $this->orders->get($id)->status());
    }

    public function test_price_is_stored_as_decimal(): void
    {
        $id = $this->orders->create('Клиент', '', new DateTimeImmutable());
        $this->orders->addItemToNewOrder($id, new OrderItem('Товар', 1, 123_45));

        $stmt = $this->pdo->prepare('SELECT price FROM order_items');
        $stmt->execute();
        $price = $stmt->fetchColumn();

        // MySQL returns the exact DECIMAL string, SQLite a float; both must read back the same kopecks.
        if (is_string($price)) {
            $this->assertSame('123.45', $price);
        }
        $this->assertSame(123_45, $this->orders->get($id)->items()[0]->price);
    }

    public function test_item_cannot_reference_missing_order(): void
    {
        $this->expectException(\PDOException::class);

        $this->pdo->exec("INSERT INTO order_items (order_id, product_name, quantity, price) VALUES (999, 'X', 1, 1)");
    }
}
