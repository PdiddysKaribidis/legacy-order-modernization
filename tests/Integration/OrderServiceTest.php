<?php

declare(strict_types=1);

namespace Tests\Integration;

use App\Order\OrderNotFoundException;
use App\Order\OrderRepository;
use App\Order\OrderService;
use App\Order\OrderStateException;
use App\Order\OrderStatus;
use App\Order\ValidationException;
use PDO;
use PHPUnit\Framework\TestCase;
use Tests\Support\TestDatabase;

final class OrderServiceTest extends TestCase
{
    private PDO $pdo;
    private OrderRepository $orders;
    private OrderService $service;

    protected function setUp(): void
    {
        $this->pdo = TestDatabase::connect();
        $this->orders = new OrderRepository($this->pdo);
        $this->service = new OrderService($this->orders);
    }

    public function test_order_goes_through_its_lifecycle(): void
    {
        $id = $this->service->createOrder('  Иван Петров ', 'ivan@example.com');
        $this->service->addItem($id, 'Кресло', '1', '12 990');
        $this->service->changeStatus($id, OrderStatus::Paid);
        $this->service->changeStatus($id, OrderStatus::Shipped);

        $order = $this->orders->get($id);
        $this->assertSame('Иван Петров', $order->customerName);
        $this->assertSame(OrderStatus::Shipped, $order->status());
        $this->assertSame(12_340_50, $order->total());
    }

    public function test_customer_data_is_validated(): void
    {
        try {
            $this->service->createOrder('', 'not-an-email');
            $this->fail('Expected ValidationException');
        } catch (ValidationException $e) {
            $this->assertSame(['customer_name', 'customer_email'], array_keys($e->errors));
        }

        $this->assertSame([], $this->orders->findAll());
    }

    public function test_email_is_optional_as_in_legacy_form(): void
    {
        $id = $this->service->createOrder('ИП Сидорова', '');

        $this->assertSame('', $this->orders->get($id)->customerEmail);
    }

    public function test_cancel_new_and_paid_orders_but_not_shipped(): void
    {
        $new = $this->orderWithItem();
        $this->service->cancelOrder($new);
        $this->assertSame(OrderStatus::Cancelled, $this->orders->get($new)->status());

        $paid = $this->orderWithItem();
        $this->service->changeStatus($paid, OrderStatus::Paid);
        $this->service->cancelOrder($paid);
        $this->assertSame(OrderStatus::Cancelled, $this->orders->get($paid)->status());

        $shipped = $this->orderWithItem();
        $this->service->changeStatus($shipped, OrderStatus::Paid);
        $this->service->changeStatus($shipped, OrderStatus::Shipped);
        $this->expectException(OrderStateException::class);
        $this->service->cancelOrder($shipped);
    }

    public function test_action_on_missing_order(): void
    {
        $this->expectException(OrderNotFoundException::class);

        $this->service->addItem(999, 'Ручка', '1', '10');
    }

    /**
     * Two managers have the same order open. One pays it; the other, with the stale page,
     * cancels it. The second change must fail instead of overwriting the first.
     */
    public function test_change_based_on_stale_status_is_rejected(): void
    {
        $id = $this->orderWithItem();
        $staleStatus = OrderStatus::New;

        $this->service->changeStatus($id, OrderStatus::Paid);

        $this->assertFalse($this->orders->updateStatus($id, $staleStatus, OrderStatus::Cancelled));
        $this->assertSame(OrderStatus::Paid, $this->orders->get($id)->status());
    }

    private function orderWithItem(): int
    {
        $id = $this->service->createOrder('Клиент', '');
        $this->service->addItem($id, 'Ручка', '1', '50');

        return $id;
    }
}
