<?php

declare(strict_types=1);

namespace App\Order;

use DateTimeImmutable;

final class Order
{
    public const DISCOUNT_THRESHOLD = 10_000_00;
    public const DISCOUNT_PERCENT = 5;

    /**
     * @param list<OrderItem> $items
     */
    public function __construct(
        public readonly int $id,
        public readonly string $customerName,
        public readonly string $customerEmail,
        private OrderStatus $status,
        private array $items,
        public readonly DateTimeImmutable $createdAt,
    ) {
    }

    public function status(): OrderStatus
    {
        return $this->status;
    }

    /**
     * @return list<OrderItem>
     */
    public function items(): array
    {
        return $this->items;
    }

    public function addItem(OrderItem $item): void
    {
        if ($this->status !== OrderStatus::New) {
            throw new OrderStateException('Позиции можно добавлять только в новый заказ.');
        }

        $this->items[] = $item;
    }

    public function changeStatus(OrderStatus $next): void
    {
        if ($next === OrderStatus::Cancelled && $this->status === OrderStatus::Shipped) {
            throw new OrderStateException('Отправленный заказ нельзя отменить');
        }

        if ($next === $this->status) {
            throw new OrderStateException("Заказ уже в статусе «{$next->label()}».");
        }

        if (!$this->status->canTransitionTo($next)) {
            throw new OrderStateException(
                "Нельзя перевести заказ из статуса «{$this->status->label()}» в «{$next->label()}»."
            );
        }

        if ($next === OrderStatus::Paid && $this->items === []) {
            throw new OrderStateException('Нельзя оплатить пустой заказ.');
        }

        $this->status = $next;
    }

    public function subtotal(): int
    {
        return array_sum(array_map(fn (OrderItem $item) => $item->lineTotal(), $this->items));
    }

    /**
     * 5% from 10 000 rubles inclusive, rounded half up to a kopeck,
     * as on the legacy order page. The legacy list page used "more than 10 000"; see docs/MODERNIZATION.md.
     */
    public function discount(): int
    {
        $subtotal = $this->subtotal();

        if ($subtotal < self::DISCOUNT_THRESHOLD) {
            return 0;
        }

        return intdiv($subtotal * self::DISCOUNT_PERCENT + 50, 100);
    }

    public function total(): int
    {
        return $this->subtotal() - $this->discount();
    }
}
