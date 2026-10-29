<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Order\OrderStatus;
use PHPUnit\Framework\TestCase;

final class OrderStatusTest extends TestCase
{
    public function test_allowed_transitions(): void
    {
        $allowed = [];
        foreach (OrderStatus::cases() as $from) {
            foreach (OrderStatus::cases() as $to) {
                if ($from->canTransitionTo($to)) {
                    $allowed[] = "{$from->value}->{$to->value}";
                }
            }
        }

        $this->assertSame(['new->paid', 'new->cancelled', 'paid->shipped', 'paid->cancelled'], $allowed);
    }

    public function test_labels_match_legacy_pages(): void
    {
        $this->assertSame(
            ['Новый', 'Оплачен', 'Отправлен', 'Отменён'],
            array_map(fn (OrderStatus $status) => $status->label(), OrderStatus::cases()),
        );
    }
}
