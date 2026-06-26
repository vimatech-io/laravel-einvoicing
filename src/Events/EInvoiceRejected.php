<?php

declare(strict_types=1);

namespace Vimatech\EInvoicing\Events;

use Vimatech\EInvoicing\Dtos\CanonicalInvoice;
use Vimatech\EInvoicing\Dtos\DispatchResult;

/**
 * Fired when a network reports a rejection or a permanent failure.
 */
final class EInvoiceRejected
{
    public function __construct(
        public readonly DispatchResult $result,
        public readonly ?CanonicalInvoice $invoice = null,
    ) {}
}
