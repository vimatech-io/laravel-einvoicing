<?php

declare(strict_types=1);

namespace Vimatech\EInvoicing\Contracts;

use Vimatech\EInvoicing\Dtos\CanonicalInvoice;
use Vimatech\EInvoicing\Dtos\DispatchResult;
use Vimatech\EInvoicing\Dtos\GeneratedDocument;
use Vimatech\EInvoicing\Dtos\InboundDocument;
use Vimatech\EInvoicing\Dtos\NetworkCapabilities;
use Vimatech\EInvoicing\Exceptions\NetworkException;

/**
 * A pluggable e-invoicing network (Peppol access point, French PDP, ...).
 *
 * Implementations must keep every vendor concept behind this boundary: callers
 * only ever exchange the package's neutral DTOs.
 */
interface EInvoiceNetwork
{
    /**
     * Stable key identifying this network instance (matches the config key).
     */
    public function key(): string;

    /**
     * Transmit a rendered document. The CanonicalInvoice is provided so the
     * driver can resolve recipient routing metadata it cannot read from the
     * opaque payload.
     *
     * @throws NetworkException
     */
    public function send(GeneratedDocument $document, CanonicalInvoice $invoice): DispatchResult;

    /**
     * Poll the lifecycle status of a previously sent message.
     *
     * @throws NetworkException
     */
    public function fetchStatus(string $messageId): DispatchResult;

    /**
     * Retrieve inbound documents waiting in the network's inbox.
     *
     * @return list<InboundDocument>
     *
     * @throws NetworkException
     */
    public function receive(): array;

    /**
     * Describe what this network can do.
     */
    public function capabilities(): NetworkCapabilities;
}
