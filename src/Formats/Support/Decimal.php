<?php

declare(strict_types=1);

namespace Vimatech\EInvoicing\Formats\Support;

/**
 * Deterministic decimal formatting for EN 16931 documents.
 */
final class Decimal
{
    /**
     * Monetary amounts: always exactly two fraction digits (BR-DEC-* rules).
     */
    public static function amount(float $value): string
    {
        return number_format(round($value, 2), 2, '.', '');
    }

    /**
     * Percentages: two fraction digits.
     */
    public static function percent(float $value): string
    {
        return number_format(round($value, 2), 2, '.', '');
    }

    /**
     * Quantities and unit prices: up to four fraction digits, trailing zeros
     * trimmed but at least two digits retained for readability.
     */
    public static function quantity(float $value): string
    {
        $formatted = number_format(round($value, 4), 4, '.', '');
        $formatted = rtrim($formatted, '0');

        if (str_ends_with($formatted, '.')) {
            $formatted .= '00';
        }

        return $formatted;
    }
}
