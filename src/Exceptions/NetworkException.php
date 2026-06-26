<?php

declare(strict_types=1);

namespace Vimatech\EInvoicing\Exceptions;

/**
 * Thrown when a network driver fails to communicate with its remote partner.
 */
final class NetworkException extends EInvoicingException
{
    public static function unknownDriver(string $key): self
    {
        return new self("No e-invoicing network driver is configured for key \"{$key}\".");
    }

    public static function transport(string $network, string $reason): self
    {
        return new self("The \"{$network}\" network failed: {$reason}");
    }
}
