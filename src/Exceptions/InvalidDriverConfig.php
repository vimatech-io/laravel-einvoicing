<?php

declare(strict_types=1);

namespace Vimatech\EInvoicing\Exceptions;

/**
 * Thrown when a network driver is configured in a way it cannot honour.
 *
 * Distinct from NetworkException so a consumer retrying a failed dispatch does
 * not retry an operator mistake that will never succeed.
 */
final class InvalidDriverConfig extends EInvoicingException
{
    public static function expected(string $network, string $key, string $expected, mixed $value): self
    {
        return new self(sprintf(
            'The "%s" network has an unusable "%s" setting: expected %s, got %s. Fix the value in config/einvoicing.php or in the environment variable it reads.',
            $network,
            $key,
            $expected,
            self::describe($value),
        ));
    }

    public static function missing(string $network, string $key, string $remedy): self
    {
        return new self(sprintf('The "%s" network has no "%s" configured. %s', $network, $key, $remedy));
    }

    public static function contradiction(string $network, string $message): self
    {
        return new self(sprintf('The "%s" network is configured inconsistently: %s', $network, $message));
    }

    private static function describe(mixed $value): string
    {
        if (is_string($value)) {
            return sprintf('the string "%s"', mb_strimwidth($value, 0, 40, '...'));
        }

        if (is_bool($value)) {
            return $value ? 'the boolean true' : 'the boolean false';
        }

        if (is_int($value) || is_float($value)) {
            return sprintf('%s %s', get_debug_type($value), var_export($value, true));
        }

        return get_debug_type($value);
    }
}
