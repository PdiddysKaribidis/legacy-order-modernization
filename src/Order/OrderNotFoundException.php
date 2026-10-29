<?php

declare(strict_types=1);

namespace App\Order;

use RuntimeException;

final class OrderNotFoundException extends RuntimeException
{
    public static function withId(int $id): self
    {
        return new self("Order {$id} not found.");
    }
}
