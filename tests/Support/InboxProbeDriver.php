<?php

declare(strict_types=1);

namespace Vimatech\EInvoicing\Tests\Support;

use Vimatech\EInvoicing\Dtos\CanonicalInvoice;
use Vimatech\EInvoicing\Dtos\DispatchResult;
use Vimatech\EInvoicing\Dtos\GeneratedDocument;
use Vimatech\EInvoicing\Dtos\InboundDocument;
use Vimatech\EInvoicing\Dtos\NetworkCapabilities;
use Vimatech\EInvoicing\Enums\Format;
use Vimatech\EInvoicing\Enums\LifecycleStatus;
use Vimatech\EInvoicing\Networks\AbstractHttpDriver;

class InboxProbeDriver extends AbstractHttpDriver
{
    public const INBOX = [
        ['id' => 'probe-pdf', 'content' => '%PDF-1.7'],
        ['id' => 'probe-unknown', 'content' => 'not an invoice'],
    ];

    public function receive(): array
    {
        return $this->inboundDocuments(self::INBOX, fn (array $item): InboundDocument => new InboundDocument(
            network: $this->key,
            messageId: (string) $item['id'],
            format: $this->inboundFormat($item, (string) $item['id'], (string) $item['content']),
            contents: (string) $item['content'],
        ));
    }

    public function send(GeneratedDocument $document, CanonicalInvoice $invoice): DispatchResult
    {
        return new DispatchResult(LifecycleStatus::Submitted, $this->key);
    }

    public function fetchStatus(string $messageId): DispatchResult
    {
        return new DispatchResult(LifecycleStatus::Unknown, $this->key, messageId: $messageId);
    }

    public function capabilities(): NetworkCapabilities
    {
        return new NetworkCapabilities($this->key, formats: [Format::Ubl], canReceive: true);
    }
}
