<?php

declare(strict_types=1);

namespace Vimatech\EInvoicing\Exceptions;

/**
 * Thrown when no network driver is configured for a destination country and
 * no fallback is defined.
 */
final class UnsupportedCountry extends EInvoicingException
{
    public function __construct(
        public readonly string $country,
        string $message = '',
    ) {
        parent::__construct(
            $message !== ''
                ? $message
                : "No e-invoicing network is configured for country \"{$country}\" and no fallback is defined.",
        );
    }
}
