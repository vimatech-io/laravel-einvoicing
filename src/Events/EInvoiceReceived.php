<?php

declare(strict_types=1);

namespace Vimatech\EInvoicing\Events;

use Vimatech\EInvoicing\Dtos\InboundDocument;

/**
 * Fired for each inbound document retrieved from a network's inbox.
 */
final class EInvoiceReceived
{
    public function __construct(
        public readonly InboundDocument $document,
    ) {}
}
