<?php

declare(strict_types=1);

namespace Vimatech\EInvoicing\Events;

use Vimatech\EInvoicing\Dtos\CanonicalInvoice;
use Vimatech\EInvoicing\Dtos\GeneratedDocument;

/**
 * Fired after a document has been successfully rendered from an invoice.
 */
final class EInvoiceGenerated
{
    public function __construct(
        public readonly CanonicalInvoice $invoice,
        public readonly GeneratedDocument $document,
    ) {}
}
