<?php

declare(strict_types=1);

namespace Vimatech\EInvoicing\Events;

use Vimatech\EInvoicing\Dtos\CanonicalInvoice;
use Vimatech\EInvoicing\Dtos\DispatchResult;

/**
 * Fired when a network reports a successful (non-rejected) hand-off.
 */
final class EInvoiceDelivered
{
    public function __construct(
        public readonly DispatchResult $result,
        public readonly ?CanonicalInvoice $invoice = null,
    ) {}
}
