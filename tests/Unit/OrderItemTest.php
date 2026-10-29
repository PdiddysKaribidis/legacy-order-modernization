<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Order\OrderItem;
use App\Order\ValidationException;
use PHPUnit\Framework\TestCase;

final class OrderItemTest extends TestCase
{
    public function test_builds_item_from_form_input(): void
    {
        $item = OrderItem::fromInput('  Бумага А4 ', ' 10 ', '389,90');

        $this->assertSame('Бумага А4', $item->productName);
        $this->assertSame(10, $item->quantity);
        $this->assertSame(38_990, $item->price);
        $this->assertSame(389_900, $item->lineTotal());
    }

    public function test_reports_all_invalid_fields_at_once(): void
    {
        try {
            OrderItem::fromInput(' ', '0', 'abc');
            $this->fail('Expected ValidationException');
        } catch (ValidationException $e) {
            $this->assertSame(['product_name', 'quantity', 'price'], array_keys($e->errors));
        }
    }

    /**
     * The legacy page cast these with (int) and (float) and saved whatever came out:
     * "2.5" became 2, "abc" became a free item.
     */
    public function test_rejects_values_the_legacy_page_silently_converted(): void
    {
        foreach ([['2.5', '100'], ['1001', '100'], ['1', '0'], ['1', 'abc'], ['1', '-5']] as [$quantity, $price]) {
            try {
                OrderItem::fromInput('Товар', $quantity, $price);
                $this->fail("Accepted quantity {$quantity} and price {$price}");
            } catch (ValidationException) {
                $this->addToAssertionCount(1);
            }
        }
    }
}
