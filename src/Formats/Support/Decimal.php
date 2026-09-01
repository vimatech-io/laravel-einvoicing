<?php

declare(strict_types=1);

namespace Vimatech\EInvoicing\Formats\Support;

use Vimatech\EInvoicing\Exceptions\EInvoicingException;

/**
 * Deterministic decimal formatting for EN 16931 documents.
 */
final class Decimal
{
    /** Two fraction digits always, per the BR-DEC-* rules. */
    public static function amount(float $value): string
    {
        return number_format(round(self::finite($value, 'amount'), 2), 2, '.', '');
    }

    public static function percent(float $value): string
    {
        return number_format(round(self::finite($value, 'percentage'), 2), 2, '.', '');
    }

    /** Quantities and unit prices: up to four fraction digits, trailing zeros trimmed. */
    public static function quantity(float $value): string
    {
        $formatted = rtrim(number_format(round(self::finite($value, 'quantity'), 4), 4, '.', ''), '0');

        if (str_ends_with($formatted, '.')) {
            $formatted .= '00';
        }

        return $formatted;
    }

    /** NAN and INF format as "nan"/"inf", which XSD decimal does not accept. */
    private static function finite(float $value, string $kind): float
    {
        if (! is_finite($value)) {
            throw new EInvoicingException(sprintf('An invoice %s is not a finite number (%s); it cannot be rendered.', $kind, var_export($value, true)));
        }

        return $value;
    }
}
