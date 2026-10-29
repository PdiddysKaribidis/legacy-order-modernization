<?php

declare(strict_types=1);

namespace App\Order;

use DateTimeImmutable;

/**
 * Changes to orders. Reads go straight to OrderRepository.
 */
final class OrderService
{
    private const STALE_PAGE = 'Заказ изменился, пока вы заполняли форму. Обновите страницу.';

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

        // Optional, as in the legacy form. Only an obvious typo is rejected: a strict check
        // (filter_var) would also reject Cyrillic addresses such as ivan@почта.рф.
        if ($customerEmail !== '' && (mb_strlen($customerEmail) > 255 || !preg_match('/^[^@\s]+@[^@\s]+\.[^@\s]+$/u', $customerEmail))) {
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
        // State first: field errors are useless on a page that no longer has the form.
        $order->assertItemsCanBeAdded();

        $item = OrderItem::fromInput($productName, $quantity, $price);
        $order->addItem($item);

        if (!$this->orders->addItemToNewOrder($orderId, $item)) {
            throw new OrderStateException(self::STALE_PAGE);
        }
    }

    /**
     * $expectedStatus is the status the manager saw when the page was rendered (a hidden form field).
     * If the order has changed since then, the action is refused instead of being applied to an order
     * the manager has not seen, e.g. cancelling an order a colleague has just marked as paid.
     */
    public function changeStatus(int $orderId, OrderStatus $status, string $expectedStatus): void
    {
        $order = $this->orders->get($orderId);

        if ($order->statusCode() !== $expectedStatus) {
            throw new OrderStateException(self::STALE_PAGE);
        }

        $previous = $order->status();
        $order->changeStatus($status);

        // $previous is not null here: changeStatus() refuses orders with an unknown status.
        if ($previous === null || !$this->orders->updateStatus($orderId, $previous, $status)) {
            throw new OrderStateException(self::STALE_PAGE);
        }
    }

    public function cancelOrder(int $orderId, string $expectedStatus): void
    {
        $this->changeStatus($orderId, OrderStatus::Cancelled, $expectedStatus);
    }
}
