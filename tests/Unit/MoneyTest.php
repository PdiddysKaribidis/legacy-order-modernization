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
            'space as thousands separator' => ['1 234,50', 123450],
            'no-break space' => ["1\u{00A0}234", 123400],
            'narrow no-break space' => ["1\u{202F}234 567", 123456700],
            'surrounding spaces' => ['  99 ', 9900],
            'zero' => ['0', 0],
            'spaces inside digits' => ['1 2 3', null],
            'wrong grouping' => ['12 34', null],
            'three decimals' => ['10,555', null],
            'negative' => ['-10', null],
            'letters' => ['abc', null],
            'empty' => ['', null],
            'exponent' => ['1e3', null],
            'too large for DECIMAL(10,2)' => ['100000000', null],
            'largest allowed' => ['99 999 999,99', 9_999_999_999],
        ];
    }

    #[DataProvider('inputs')]
    public function test_parse(string $input, ?int $expected): void
    {
        $this->assertSame($expected, Money::parse($input));
    }

    public function test_format_matches_legacy_number_format(): void
    {
        foreach ([0, 5, 100, 123450, 1_234_567_89, 9_999_999_999, -150, -1_000_000] as $kopecks) {
            $this->assertSame(
                number_format($kopecks / 100, 2, ',', ' '),
                Money::format($kopecks),
                "Formatting of {$kopecks} kopecks differs from the legacy pages",
            );
        }
    }

    /**
     * @return array<string, array{string|float, int}>
     */
    public static function decimals(): array
    {
        return [
            'MySQL string' => ['389.90', 38990],
            'small amount' => ['0.05', 5],
            'one decimal digit' => ['12.5', 1250],
            'no decimals' => ['100', 10000],
            'negative from legacy data' => ['-10.00', -1000],
            'SQLite float' => [389.9, 38990],
            'float that is not exact in binary' => [0.29, 29],
        ];
    }

    #[DataProvider('decimals')]
    public function test_reads_decimal_from_database(string|float $value, int $kopecks): void
    {
        $this->assertSame($kopecks, Money::fromDecimal($value));
    }

    public function test_writes_decimal(): void
    {
        $this->assertSame('389.90', Money::toDecimal(38990));
        $this->assertSame('0.05', Money::toDecimal(5));
        $this->assertSame('-1.50', Money::toDecimal(-150));
    }
}
