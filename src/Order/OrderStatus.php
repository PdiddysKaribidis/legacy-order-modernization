<?php

declare(strict_types=1);

namespace App\Order;

enum OrderStatus: string
{
    case New = 'new';
    case Paid = 'paid';
    case Shipped = 'shipped';
    case Cancelled = 'cancelled';

    public function label(): string
    {
        return match ($this) {
            self::New => 'Новый',
            self::Paid => 'Оплачен',
            self::Shipped => 'Отправлен',
            self::Cancelled => 'Отменён',
        };
    }

    public function canTransitionTo(self $next): bool
    {
        $allowed = match ($this) {
            self::New => [self::Paid, self::Cancelled],
            self::Paid => [self::Shipped, self::Cancelled],
            self::Shipped, self::Cancelled => [],
        };

        return in_array($next, $allowed, true);
    }
}
