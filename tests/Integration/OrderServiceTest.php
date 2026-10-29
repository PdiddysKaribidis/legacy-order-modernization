<?php

declare(strict_types=1);

namespace Tests\Integration;

use App\Order\OrderNotFoundException;
use App\Order\OrderRepository;
use App\Order\OrderService;
use App\Order\OrderStateException;
use App\Order\OrderStatus;
use App\Order\ValidationException;
use PHPUnit\Framework\TestCase;
use Tests\Support\TestDatabase;

final class OrderServiceTest extends TestCase
{
    private OrderRepository $orders;
    private OrderService $service;

    protected function setUp(): void
    {
        $this->orders = new OrderRepository(TestDatabase::connect());
        $this->service = new OrderService($this->orders);
    }

    public function test_order_goes_through_its_lifecycle(): void
    {
        $id = $this->service->createOrder('  Иван Петров ', 'ivan@example.com');
        $this->service->addItem($id, 'Кресло', '1', '12 990');
        $this->service->changeStatus($id, OrderStatus::Paid, 'new');
        $this->service->changeStatus($id, OrderStatus::Shipped, 'paid');

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

    public function test_cyrillic_email_is_accepted(): void
    {
        $id = $this->service->createOrder('Иван', 'иван@почта.рф');

        $this->assertSame('иван@почта.рф', $this->orders->get($id)->customerEmail);
    }

    public function test_email_longer_than_column_is_rejected(): void
    {
        $this->expectException(ValidationException::class);

        $this->service->createOrder('Иван', str_repeat('a', 250) . '@example.com');
    }

    public function test_cancel_new_and_paid_orders_but_not_shipped(): void
    {
        $new = $this->orderWithItem();
        $this->service->cancelOrder($new, 'new');
        $this->assertSame(OrderStatus::Cancelled, $this->orders->get($new)->status());

        $paid = $this->orderWithItem();
        $this->service->changeStatus($paid, OrderStatus::Paid, 'new');
        $this->service->cancelOrder($paid, 'paid');
        $this->assertSame(OrderStatus::Cancelled, $this->orders->get($paid)->status());

        $shipped = $this->orderWithItem();
        $this->service->changeStatus($shipped, OrderStatus::Paid, 'new');
        $this->service->changeStatus($shipped, OrderStatus::Shipped, 'paid');
        $this->expectException(OrderStateException::class);
        $this->service->cancelOrder($shipped, 'shipped');
    }

    public function test_action_on_missing_order(): void
    {
        $this->expectException(OrderNotFoundException::class);

        $this->service->addItem(999, 'Ручка', '1', '10');
    }

    /**
     * Two managers have the same new order open. One marks it as paid; the other,
     * still looking at the page with status "new", clicks "Cancel". The cancel must
     * be refused instead of silently cancelling a paid order.
     */
    public function test_action_from_stale_page_is_refused(): void
    {
        $id = $this->orderWithItem();
        $this->service->changeStatus($id, OrderStatus::Paid, 'new');

        try {
            $this->service->cancelOrder($id, 'new');
            $this->fail('Expected OrderStateException');
        } catch (OrderStateException $e) {
            $this->assertStringContainsString('Заказ изменился', $e->getMessage());
        }

        $this->assertSame(OrderStatus::Paid, $this->orders->get($id)->status());
    }

    public function test_state_is_checked_before_item_fields(): void
    {
        $id = $this->orderWithItem();
        $this->service->changeStatus($id, OrderStatus::Paid, 'new');

        $this->expectException(OrderStateException::class);
        $this->service->addItem($id, '', 'abc', 'abc');
    }

    private function orderWithItem(): int
    {
        $id = $this->service->createOrder('Клиент', '');
        $this->service->addItem($id, 'Ручка', '1', '50');

        return $id;
    }
}
