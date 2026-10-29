<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Order\Money;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class MoneyTest extends TestCase
{
    /**
     * @return array<string, array{string, ?int}>
     */
    public static function inputs(): array
    {
        return [
            'integer' => ['1500', 150000],
            'comma decimals' => ['1500,5', 150050],
            'dot decimals' => ['1500.55', 150055],
            'spaces as thousands separator' => ['1 234,50', 123450],
            'surrounding spaces' => ['  99 ', 9900],
            'zero' => ['0', 0],
            'three decimals' => ['10,555', null],
            'negative' => ['-10', null],
            'letters' => ['abc', null],
            'empty' => ['', null],
            'exponent' => ['1e3', null],
            'too large for DECIMAL(10,2)' => ['100000000', null],
            'largest allowed' => ['99999999,99', 9_999_999_999],
        ];
    }

    #[DataProvider('inputs')]
    public function test_parse(string $input, ?int $expected): void
    {
        $this->assertSame($expected, Money::parse($input));
    }

    public function test_format_matches_legacy_number_format(): void
    {
        foreach ([0, 5, 100, 123450, 1_234_567_89, 9_999_999_999] as $kopecks) {
            $this->assertSame(
                number_format($kopecks / 100, 2, ',', ' '),
                Money::format($kopecks),
                "Formatting of {$kopecks} kopecks differs from the legacy pages",
            );
        }
    }

    public function test_decimal_round_trip(): void
    {
        $this->assertSame('389.90', Money::toDecimal(38990));
        $this->assertSame('0.05', Money::toDecimal(5));
        $this->assertSame(38990, Money::fromDecimal('389.90'));
        $this->assertSame(38990, Money::fromDecimal(389.9));
    }
}
