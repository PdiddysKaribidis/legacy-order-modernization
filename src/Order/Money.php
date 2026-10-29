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
     * Returns null for anything that is not a non-negative amount with at most two decimals.
     */
    public static function parse(string $input): ?int
    {
        $normalized = str_replace([' ', "\u{00A0}"], '', trim($input));

        if (!preg_match('/^(\d{1,10})(?:[.,](\d{1,2}))?$/', $normalized, $matches)) {
            return null;
        }

        $kopecks = (int) $matches[1] * 100 + (int) str_pad($matches[2] ?? '', 2, '0');

        return $kopecks <= self::MAX_KOPECKS ? $kopecks : null;
    }

    /**
     * Reads a DECIMAL(10,2) value as returned by PDO: a string for MySQL, a string or float for SQLite.
     */
    public static function fromDecimal(string|float|int $value): int
    {
        return (int) round((float) $value * 100);
    }

    public static function toDecimal(int $kopecks): string
    {
        return sprintf('%d.%02d', intdiv($kopecks, 100), $kopecks % 100);
    }

    /**
     * Same output as the legacy number_format($amount, 2, ',', ' '), e.g. "12 345,60".
     */
    public static function format(int $kopecks): string
    {
        return number_format(intdiv($kopecks, 100), 0, '', ' ') . ',' . sprintf('%02d', $kopecks % 100);
    }
}
