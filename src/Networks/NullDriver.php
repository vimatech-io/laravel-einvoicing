<?php

declare(strict_types=1);

namespace Vimatech\EInvoicing\Networks;

use Vimatech\EInvoicing\Contracts\EInvoiceNetwork;
use Vimatech\EInvoicing\Dtos\CanonicalInvoice;
use Vimatech\EInvoicing\Dtos\DispatchResult;
use Vimatech\EInvoicing\Dtos\GeneratedDocument;
use Vimatech\EInvoicing\Dtos\NetworkCapabilities;
use Vimatech\EInvoicing\Enums\Format;
use Vimatech\EInvoicing\Enums\LifecycleStatus;

/**
 * A network that accepts everything and transmits nothing.
 *
 * Useful as a safe default in local/staging environments where no real access
 * point is configured.
 */
final class NullDriver implements EInvoiceNetwork
{
    public function __construct(private readonly string $key = 'null') {}

    public function key(): string
    {
        return $this->key;
    }

    public function send(GeneratedDocument $document, CanonicalInvoice $invoice): DispatchResult
    {
        return new DispatchResult(
            status: LifecycleStatus::Submitted,
            network: $this->key,
            messageId: null,
            occurredAt: new \DateTimeImmutable,
        );
    }

    public function fetchStatus(string $messageId): DispatchResult
    {
        return new DispatchResult(
            status: LifecycleStatus::Unknown,
            network: $this->key,
            messageId: $messageId,
        );
    }

    public function receive(): array
    {
        return [];
    }

    public function capabilities(): NetworkCapabilities
    {
        return new NetworkCapabilities(
            network: $this->key,
            formats: [Format::Ubl, Format::Cii],
            canSend: true,
            canFetchStatus: false,
            canReceive: false,
        );
    }
}
