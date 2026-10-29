<?php

declare(strict_types=1);

namespace App\Order;

/**
 * Amounts are integers in kopecks. The legacy code used floats, which cannot
 * represent most decimal fractions exactly and made discounts round unpredictably.
 */
final class Money
{
    /** DECIMAL(10,2) in the database: up to 99 999 999,99. */
    public const MAX_KOPECKS = 9_999_999_999;

    /**
     * Accepts what users type in the price field: "1500", "1 500,5", "1500.50".
     * Spaces are allowed only as thousands separators. Returns null for anything that is
     * not a non-negative amount with at most two decimals.
     */
    public static function parse(string $input): ?int
    {
        // Regular, no-break and narrow no-break spaces all appear when numbers are copied from documents.
        $normalized = str_replace(["\u{00A0}", "\u{202F}"], ' ', trim($input));

        if (!preg_match('/^(\d{1,3}(?: \d{3})+|\d{1,10})(?:[.,](\d{1,2}))?$/', $normalized, $matches)) {
            return null;
        }

        $kopecks = (int) str_replace(' ', '', $matches[1]) * 100 + (int) str_pad($matches[2] ?? '', 2, '0');

        return $kopecks <= self::MAX_KOPECKS ? $kopecks : null;
    }

    /**
     * Reads a DECIMAL(10,2) value as returned by PDO: a string for MySQL, a float or string for SQLite.
     * A string is parsed digit by digit, so no float rounding is involved.
     */
    public static function fromDecimal(string|float|int $value): int
    {
        $decimal = is_string($value) ? trim($value) : sprintf('%.2F', $value);

        if (!preg_match('/^(-?)(\d+)(?:\.(\d+))?$/', $decimal, $matches)) {
            throw new \UnexpectedValueException("Not a decimal amount: {$decimal}");
        }

        $kopecks = (int) $matches[2] * 100 + (int) substr(str_pad($matches[3] ?? '', 2, '0'), 0, 2);

        return $matches[1] === '-' ? -$kopecks : $kopecks;
    }

    public static function toDecimal(int $kopecks): string
    {
        $sign = $kopecks < 0 ? '-' : '';
        $kopecks = abs($kopecks);

        return sprintf('%s%d.%02d', $sign, intdiv($kopecks, 100), $kopecks % 100);
    }

    /**
     * Same output as the legacy number_format($amount, 2, ',', ' '), e.g. "12 345,60".
     * Negative amounts can come from old data: the legacy form saved "-10" as is.
     */
    public static function format(int $kopecks): string
    {
        $sign = $kopecks < 0 ? '-' : '';
        $kopecks = abs($kopecks);

        return $sign . number_format(intdiv($kopecks, 100), 0, '', ' ') . ',' . sprintf('%02d', $kopecks % 100);
    }
}
