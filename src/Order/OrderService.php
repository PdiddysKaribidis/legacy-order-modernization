<?php

declare(strict_types=1);

namespace App\Order;

use DateTimeImmutable;

/**
 * Changes to orders. Reads go straight to OrderRepository.
 */
final class OrderService
{
    public function __construct(private readonly OrderRepository $orders)
    {
    }

    public function createOrder(string $customerName, string $customerEmail): int
    {
        $customerName = trim($customerName);
        $customerEmail = trim($customerEmail);
        $errors = [];

        if ($customerName === '') {
            $errors['customer_name'] = 'Укажите имя клиента.';
        } elseif (mb_strlen($customerName) > 100) {
            $errors['customer_name'] = 'Имя клиента — не длиннее 100 символов.';
        }

        // E-mail stays optional, as in the legacy form, but must be valid when given.
        if ($customerEmail !== '' && filter_var($customerEmail, FILTER_VALIDATE_EMAIL) === false) {
            $errors['customer_email'] = 'Проверьте адрес e-mail.';
        }

        if ($errors !== []) {
            throw new ValidationException($errors);
        }

        return $this->orders->create($customerName, $customerEmail, new DateTimeImmutable());
    }

    public function addItem(int $orderId, string $productName, string $quantity, string $price): void
    {
        $order = $this->orders->get($orderId);
        $item = OrderItem::fromInput($productName, $quantity, $price);

        $order->addItem($item);

        if (!$this->orders->addItemToNewOrder($orderId, $item)) {
            throw new OrderStateException('Заказ изменился, пока вы заполняли форму. Обновите страницу.');
        }
    }

    public function changeStatus(int $orderId, OrderStatus $status): void
    {
        $order = $this->orders->get($orderId);
        $previous = $order->status();

        $order->changeStatus($status);

        if (!$this->orders->updateStatus($orderId, $previous, $status)) {
            throw new OrderStateException('Заказ изменился, пока вы заполняли форму. Обновите страницу.');
        }
    }

    public function cancelOrder(int $orderId): void
    {
        $this->changeStatus($orderId, OrderStatus::Cancelled);
    }
}
