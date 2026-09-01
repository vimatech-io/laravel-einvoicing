<?php

declare(strict_types=1);

namespace Vimatech\EInvoicing\Exceptions;

use Throwable;

/**
 * Thrown when a network driver fails to reach its remote partner, or when the
 * partner returns something the driver cannot use.
 */
final class NetworkException extends EInvoicingException
{
    public static function unknownDriver(string $key): self
    {
        return new self("No e-invoicing network driver is configured for key \"{$key}\".");
    }

    public static function transport(string $network, string $reason, ?Throwable $previous = null): self
    {
        return new self("The \"{$network}\" network failed: {$reason}", 0, $previous);
    }

    public static function malformedInbound(string $network, string $messageId, string $reason): self
    {
        $document = $messageId === '' ? 'a document' : "document \"{$messageId}\"";

        return new self("The \"{$network}\" network returned {$document} that cannot be read: {$reason}.");
    }
}
