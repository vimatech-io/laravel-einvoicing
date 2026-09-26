<?php

declare(strict_types=1);

namespace Vimatech\EInvoicing\Formats\Support;

use Vimatech\EInvoicing\Exceptions\EInvoicingException;
use Vimatech\EInvoicing\Exceptions\InvalidInvoice;

/**
 * Deterministic decimal formatting for EN 16931 documents. A value that does
 * not fit its format is refused, never rounded into one that does.
 */
final class Decimal
{
    /** BR-DEC-*: every amount carries at most two fraction digits. */
    private const AMOUNT_DECIMALS = 2;

    private const PERCENT_DECIMALS = 4;

    private const UNIT_PRICE_DECIMALS = 6;

    /**
     * Relative gap under which a float is taken to be the decimal it was built
     * from (0.1 + 0.2 is 0.30), not a value with more digits than it shows.
     */
    private const FLOAT_NOISE = 1e-12;

    public static function amount(float $value, string $term = 'amount'): string
    {
        $exact = self::exact($value, self::AMOUNT_DECIMALS, $term, 'EN 16931 allows at most 2 decimals in an amount (BR-DEC)');

        return number_format($exact, self::AMOUNT_DECIMALS, '.', '');
    }

    public static function percent(float $value, string $term = 'percentage'): string
    {
        return self::trimmed(self::exact($value, self::PERCENT_DECIMALS, $term, 'a VAT rate is rendered with at most 4 decimals'), self::PERCENT_DECIMALS);
    }

    public static function unitPrice(float $value, string $term = 'unit price'): string
    {
        return self::trimmed(self::exact($value, self::UNIT_PRICE_DECIMALS, $term, 'a unit price is rendered with at most 6 decimals'), self::UNIT_PRICE_DECIMALS);
    }

    /** Quantities: up to four fraction digits, trailing zeros trimmed. */
    public static function quantity(float $value): string
    {
        $formatted = rtrim(number_format(round(self::finite($value, 'quantity'), 4), 4, '.', ''), '0');

        if (str_ends_with($formatted, '.')) {
            $formatted .= '00';
        }

        return $formatted;
    }

    private static function exact(float $value, int $decimals, string $term, string $rule): float
    {
        $scaled = self::finite($value, $term) * 10 ** $decimals;
        $nearest = round($scaled);

        if (abs($scaled - $nearest) > max(1.0, abs($scaled)) * self::FLOAT_NOISE) {
            throw InvalidInvoice::withViolations([
                sprintf('%s: %s has more than %d decimals and would be altered by rounding; %s', $term, var_export($value, true), $decimals, $rule),
            ]);
        }

        return round($value, $decimals);
    }

    private static function trimmed(float $value, int $decimals): string
    {
        [$units, $fraction] = explode('.', number_format($value, $decimals, '.', ''));

        return $units.'.'.str_pad(rtrim($fraction, '0'), 2, '0');
    }

    /** NAN and INF format as "nan"/"inf", which XSD decimal does not accept. */
    private static function finite(float $value, string $term): float
    {
        if (! is_finite($value)) {
            throw new EInvoicingException(sprintf('%s: %s is not a finite number; it cannot be rendered.', $term, var_export($value, true)));
        }

        return $value;
    }
}
