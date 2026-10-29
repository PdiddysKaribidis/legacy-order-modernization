<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Order\Order;
use App\Order\OrderItem;
use App\Order\OrderStateException;
use App\Order\OrderStatus;
use DateTimeImmutable;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class OrderTest extends TestCase
{
    /**
     * @return array<string, array{int, int, int}> subtotal => expected discount and total, in kopecks
     */
    public static function discounts(): array
    {
        return [
            'below threshold' => [999_999, 0, 999_999],
            'exactly 10 000 rubles' => [1_000_000, 50_000, 950_000],
            'above threshold' => [1_879_900, 93_995, 1_785_905],
            'half a kopeck rounds up' => [1_000_010, 50_001, 950_009],
            'below half a kopeck rounds down' => [1_000_009, 50_000, 950_009],
        ];
    }

    #[DataProvider('discounts')]
    public function test_discount_is_five_percent_from_ten_thousand_rubles(int $subtotal, int $discount, int $total): void
    {
        $order = $this->order(OrderStatus::New, [new OrderItem('Товар', 1, $subtotal)]);

        $this->assertSame($subtotal, $order->subtotal());
        $this->assertSame($discount, $order->discount());
        $this->assertSame($total, $order->total());
    }

    public function test_totals_sum_all_lines(): void
    {
        $order = $this->order(OrderStatus::New, [
            new OrderItem('Бумага', 10, 38_990),
            new OrderItem('Картридж', 2, 745_000),
        ]);

        $this->assertSame(1_879_900, $order->subtotal());
    }

    public function test_empty_order_costs_nothing(): void
    {
        $this->assertSame(0, $this->order(OrderStatus::New)->total());
    }

    public function test_items_can_be_added_only_to_new_order(): void
    {
        $order = $this->order(OrderStatus::New);
        $order->addItem(new OrderItem('Ручка', 1, 5_000));
        $this->assertCount(1, $order->items());

        $paid = $this->order(OrderStatus::Paid, [new OrderItem('Ручка', 1, 5_000)]);
        $this->expectException(OrderStateException::class);
        $paid->addItem(new OrderItem('Карандаш', 1, 2_000));
    }

    public function test_empty_order_cannot_be_paid(): void
    {
        $this->expectException(OrderStateException::class);
        $this->expectExceptionMessage('Нельзя оплатить пустой заказ.');

        $this->order(OrderStatus::New)->changeStatus(OrderStatus::Paid);
    }

    public function test_full_lifecycle(): void
    {
        $order = $this->order(OrderStatus::New, [new OrderItem('Ручка', 1, 5_000)]);

        $order->changeStatus(OrderStatus::Paid);
        $order->changeStatus(OrderStatus::Shipped);

        $this->assertSame(OrderStatus::Shipped, $order->status());
    }

    public function test_shipped_order_cannot_be_cancelled(): void
    {
        $order = $this->order(OrderStatus::Shipped, [new OrderItem('Ручка', 1, 5_000)]);

        $this->expectException(OrderStateException::class);
        $order->changeStatus(OrderStatus::Cancelled);
    }

    public function test_setting_the_same_status_is_rejected(): void
    {
        $this->expectException(OrderStateException::class);
        $this->expectExceptionMessage('Заказ уже в статусе «Отменён».');

        $this->order(OrderStatus::Cancelled)->changeStatus(OrderStatus::Cancelled);
    }

    /**
     * The legacy status form saved any value; such an order must still be readable.
     */
    public function test_order_with_unknown_status_is_shown_but_cannot_be_changed(): void
    {
        $order = new Order(1, 'Клиент', '', 'archived', [], new DateTimeImmutable());

        $this->assertNull($order->status());
        $this->assertSame('archived', $order->statusLabel());

        $this->expectException(OrderStateException::class);
        $this->expectExceptionMessage('неизвестный статус «archived»');
        $order->changeStatus(OrderStatus::Cancelled);
    }

    public function test_items_cannot_be_added_to_order_with_unknown_status(): void
    {
        $this->expectException(OrderStateException::class);

        (new Order(1, 'Клиент', '', 'archived', [], new DateTimeImmutable()))->assertItemsCanBeAdded();
    }

    /**
     * @param list<OrderItem> $items
     */
    private function order(OrderStatus $status, array $items = []): Order
    {
        return new Order(1, 'Иван Петров', 'ivan@example.com', $status->value, $items, new DateTimeImmutable('2026-10-01 10:00'));
    }
}
