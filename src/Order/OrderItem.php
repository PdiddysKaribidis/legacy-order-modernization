<?php

declare(strict_types=1);

namespace App\Order;

final class OrderItem
{
    public const MAX_QUANTITY = 1000;

    public function __construct(
        public readonly string $productName,
        public readonly int $quantity,
        public readonly int $price,
    ) {
    }

    /**
     * Builds an item from form input. All problems are reported at once, so the form can show them together.
     */
    public static function fromInput(string $productName, string $quantity, string $price): self
    {
        $errors = [];

        $productName = trim($productName);
        if ($productName === '') {
            $errors['product_name'] = 'Укажите товар.';
        } elseif (mb_strlen($productName) > 200) {
            $errors['product_name'] = 'Название товара — не длиннее 200 символов.';
        }

        $parsedQuantity = filter_var(trim($quantity), FILTER_VALIDATE_INT, [
            'options' => ['min_range' => 1, 'max_range' => self::MAX_QUANTITY],
        ]);
        if ($parsedQuantity === false) {
            $errors['quantity'] = 'Количество — целое число от 1 до ' . self::MAX_QUANTITY . '.';
        }

        $parsedPrice = Money::parse($price);
        if ($parsedPrice === null || $parsedPrice === 0) {
            $errors['price'] = 'Цена — положительное число, например 1500 или 1500,50.';
        }

        if ($errors !== []) {
            throw new ValidationException($errors);
        }

        return new self($productName, (int) $parsedQuantity, (int) $parsedPrice);
    }

    public function lineTotal(): int
    {
        return $this->price * $this->quantity;
    }
}
