<?php

declare(strict_types=1);

namespace Vimatech\EInvoicing\Events;

use Vimatech\EInvoicing\Dtos\CanonicalInvoice;
use Vimatech\EInvoicing\Dtos\DispatchResult;
use Vimatech\EInvoicing\Dtos\GeneratedDocument;

/**
 * Fired after a document has been handed to a network for transmission,
 * regardless of the resulting lifecycle status.
 */
final class EInvoiceDispatched
{
    public function __construct(
        public readonly CanonicalInvoice $invoice,
        public readonly GeneratedDocument $document,
        public readonly DispatchResult $result,
    ) {}
}
