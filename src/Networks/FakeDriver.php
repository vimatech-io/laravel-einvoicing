<?php

declare(strict_types=1);

namespace Vimatech\EInvoicing\Networks;

use Closure;
use DateTimeImmutable;
use RuntimeException;
use Vimatech\EInvoicing\Contracts\EInvoiceNetwork;
use Vimatech\EInvoicing\Dtos\CanonicalInvoice;
use Vimatech\EInvoicing\Dtos\DispatchResult;
use Vimatech\EInvoicing\Dtos\GeneratedDocument;
use Vimatech\EInvoicing\Dtos\InboundDocument;
use Vimatech\EInvoicing\Dtos\NetworkCapabilities;
use Vimatech\EInvoicing\Enums\Format;
use Vimatech\EInvoicing\Enums\LifecycleStatus;

/**
 * An in-memory network for tests.
 *
 * Ship-friendly: it has no test-framework dependency, so consuming
 * applications can bind it as a network driver and assert against it with the
 * inspection API ({@see sent()}, {@see sentCount()}) or the convenience
 * assert* helpers.
 *
 * @phpstan-type SentRecord array{document: GeneratedDocument, invoice: CanonicalInvoice, result: DispatchResult}
 */
final class FakeDriver implements EInvoiceNetwork
{
    /** @var list<SentRecord> */
    private array $sent = [];

    /** @var array<string, DispatchResult> */
    private array $statuses = [];

    /** @var list<InboundDocument> */
    private array $inbox = [];

    private int $sequence = 0;

    public function __construct(
        private readonly string $key = 'fake',
        private LifecycleStatus $defaultStatus = LifecycleStatus::Delivered,
    ) {}

    public function key(): string
    {
        return $this->key;
    }

    /**
     * Set the lifecycle status returned by subsequent send() calls.
     */
    public function alwaysReturn(LifecycleStatus $status): self
    {
        $this->defaultStatus = $status;

        return $this;
    }

    /**
     * Queue an inbound document to be returned by the next receive() call.
     */
    public function pushInbound(InboundDocument $document): self
    {
        $this->inbox[] = $document;

        return $this;
    }

    public function send(GeneratedDocument $document, CanonicalInvoice $invoice): DispatchResult
    {
        $messageId = sprintf('fake-%s-%04d', $this->key, ++$this->sequence);

        $result = new DispatchResult(
            status: $this->defaultStatus,
            network: $this->key,
            messageId: $messageId,
            transmissionId: $messageId,
            occurredAt: new DateTimeImmutable,
            raw: ['fake' => true, 'invoice' => $invoice->number],
        );

        $this->sent[] = ['document' => $document, 'invoice' => $invoice, 'result' => $result];
        $this->statuses[$messageId] = $result;

        return $result;
    }

    public function fetchStatus(string $messageId): DispatchResult
    {
        return $this->statuses[$messageId] ?? new DispatchResult(
            status: LifecycleStatus::Unknown,
            network: $this->key,
            messageId: $messageId,
        );
    }

    public function receive(): array
    {
        $documents = $this->inbox;
        $this->inbox = [];

        return $documents;
    }

    public function capabilities(): NetworkCapabilities
    {
        return new NetworkCapabilities(
            network: $this->key,
            formats: [Format::Ubl, Format::Cii],
            canSend: true,
            canFetchStatus: true,
            canReceive: true,
        );
    }

    // -- Inspection API ----------------------------------------------------

    /**
     * @return list<SentRecord>
     */
    public function sent(?Closure $filter = null): array
    {
        if ($filter === null) {
            return $this->sent;
        }

        return array_values(array_filter(
            $this->sent,
            static fn (array $record): bool => $filter($record['invoice'], $record['document']) === true,
        ));
    }

    public function sentCount(): int
    {
        return count($this->sent);
    }

    public function hasSent(?Closure $filter = null): bool
    {
        return $this->sent($filter) !== [];
    }

    public function lastSent(): ?CanonicalInvoice
    {
        $record = end($this->sent);

        return $record === false ? null : $record['invoice'];
    }

    // -- Convenience assertions -------------------------------------------

    public function assertSent(?Closure $filter = null): void
    {
        if (! $this->hasSent($filter)) {
            throw new RuntimeException('Expected an invoice to have been sent through the fake network, but none matched.');
        }
    }

    public function assertNothingSent(): void
    {
        if ($this->sent !== []) {
            throw new RuntimeException(sprintf('Expected nothing to be sent, but %d document(s) were.', $this->sentCount()));
        }
    }

    public function assertSentCount(int $expected): void
    {
        if ($this->sentCount() !== $expected) {
            throw new RuntimeException(sprintf('Expected %d sent document(s), but found %d.', $expected, $this->sentCount()));
        }
    }
}
