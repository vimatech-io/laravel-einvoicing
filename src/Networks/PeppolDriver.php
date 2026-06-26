<?php

declare(strict_types=1);

namespace Vimatech\EInvoicing\Networks;

use DateTimeImmutable;
use Vimatech\EInvoicing\Dtos\CanonicalInvoice;
use Vimatech\EInvoicing\Dtos\DispatchResult;
use Vimatech\EInvoicing\Dtos\GeneratedDocument;
use Vimatech\EInvoicing\Dtos\InboundDocument;
use Vimatech\EInvoicing\Dtos\NetworkCapabilities;
use Vimatech\EInvoicing\Dtos\Party;
use Vimatech\EInvoicing\Enums\Format;
use Vimatech\EInvoicing\Enums\LifecycleStatus;

/**
 * Driver for a Peppol access-point partner exposing a REST API.
 *
 * The request/response contract below is the package's neutral adapter shape.
 * Most commercial access points (Storecove, Tradeshift, Ibanity, Recommand,
 * ...) either match it or sit one thin mapping away; override the paths and
 * the status_map via config to fit your partner without touching this class.
 *
 * Sender/receiver Peppol identifiers are derived from the CanonicalInvoice
 * parties, so a vendor's addressing scheme never leaks into the domain model.
 */
final class PeppolDriver extends AbstractHttpDriver
{
    public function send(GeneratedDocument $document, CanonicalInvoice $invoice): DispatchResult
    {
        $response = $this->exchange(fn () => $this->request()->post($this->config->path('send', '/documents'), [
            'format' => $document->format->value,
            'profile' => $document->profile,
            'sender' => $this->peppolAddress($invoice->seller),
            'receiver' => $this->peppolAddress($invoice->buyer),
            'document' => $document->toBase64(),
            'documentType' => $invoice->isCreditNote() ? 'credit-note' : 'invoice',
        ]));

        $payload = $this->payload($response);
        $providerStatus = $this->stringOrNull($payload['status'] ?? null);

        return new DispatchResult(
            status: $providerStatus === null ? LifecycleStatus::Submitted : $this->mapStatus($providerStatus),
            network: $this->key,
            messageId: $this->stringOrNull($payload['id'] ?? $payload['messageId'] ?? null),
            transmissionId: $this->stringOrNull($payload['transmissionId'] ?? null),
            reason: $this->stringOrNull($payload['reason'] ?? null),
            occurredAt: new DateTimeImmutable,
            raw: $payload,
        );
    }

    public function fetchStatus(string $messageId): DispatchResult
    {
        $path = str_replace('{id}', rawurlencode($messageId), $this->config->path('status', '/documents/{id}/status'));

        $response = $this->exchange(fn () => $this->request()->get($path));
        $payload = $this->payload($response);

        return new DispatchResult(
            status: $this->mapStatus($this->stringOrNull($payload['status'] ?? null)),
            network: $this->key,
            messageId: $messageId,
            transmissionId: $this->stringOrNull($payload['transmissionId'] ?? null),
            reason: $this->stringOrNull($payload['reason'] ?? null),
            occurredAt: new DateTimeImmutable,
            raw: $payload,
        );
    }

    public function receive(): array
    {
        $response = $this->exchange(fn () => $this->request()->get($this->config->path('inbound', '/inbound')));

        $documents = [];
        foreach ($this->payloadList($response, 'documents') as $item) {
            $documents[] = $this->toInbound($item);
        }

        return $documents;
    }

    public function capabilities(): NetworkCapabilities
    {
        return new NetworkCapabilities(
            network: $this->key,
            formats: [Format::Ubl],
            countries: $this->config->stringList('countries'),
            canSend: true,
            canFetchStatus: true,
            canReceive: true,
        );
    }

    /**
     * @return array<string, string|null>
     */
    private function peppolAddress(Party $party): array
    {
        return [
            'scheme' => $party->endpointScheme,
            'id' => $party->endpointId,
            'country' => $party->countryCode,
        ];
    }

    /**
     * @param  array<string, mixed>  $item
     */
    private function toInbound(array $item): InboundDocument
    {
        $raw = $item['document'] ?? null;
        $contents = is_string($raw)
            ? (base64_decode($raw, true) ?: $raw)
            : ($this->stringOrNull($item['payload'] ?? null) ?? '');

        return new InboundDocument(
            network: $this->key,
            messageId: $this->stringOrNull($item['id'] ?? $item['messageId'] ?? null) ?? '',
            format: Format::tryFrom($this->stringOrNull($item['format'] ?? null) ?? 'ubl') ?? Format::Ubl,
            contents: $contents,
            senderId: $this->stringOrNull($item['sender'] ?? null),
            receivedAt: new DateTimeImmutable,
            raw: $item,
        );
    }
}
