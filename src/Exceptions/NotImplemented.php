<?php

declare(strict_types=1);

namespace Vimatech\EInvoicing\Exceptions;

/**
 * Thrown when a requested capability is intentionally not yet implemented.
 */
final class NotImplemented extends EInvoicingException
{
    public static function format(string $format): self
    {
        return new self("The \"{$format}\" format is not implemented yet.");
    }

    public static function capability(string $capability, string $network): self
    {
        return new self("The \"{$network}\" network does not support \"{$capability}\".");
    }
}
