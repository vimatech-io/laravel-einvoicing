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
 * Driver for a French PDP (Plateforme de Dématérialisation Partenaire),
 * exposing a REST API.
 *
 * Under the French reform, invoices are submitted to an accredited PDP which
 * routes them to the recipient and reports lifecycle statuses. The neutral
 * adapter shape mirrors the common PDP contract; tune paths and status_map via
 * config for your provider. The buyer's SIREN/SIRET travels as the recipient
 * identifier without entering the canonical model.
 */
final class FrPdpDriver extends AbstractHttpDriver
{
    public function send(GeneratedDocument $document, CanonicalInvoice $invoice): DispatchResult
    {
        $response = $this->exchange(fn () => $this->request()->post($this->config->path('send', '/invoices'), [
            'format' => $document->format->value,
            'profile' => $document->profile,
            'supplier' => $this->frenchIdentifier($invoice->seller),
            'customer' => $this->frenchIdentifier($invoice->buyer),
            'content' => $document->toBase64(),
            'lifecycle' => $invoice->isCreditNote() ? 'credit_note' : 'invoice',
        ]));

        $payload = $this->payload($response);
        $providerStatus = $this->stringOrNull($payload['status'] ?? $payload['statut'] ?? null);

        return new DispatchResult(
            status: $providerStatus === null ? LifecycleStatus::Submitted : $this->mapStatus($providerStatus),
            network: $this->key,
            messageId: $this->stringOrNull($payload['id'] ?? $payload['invoiceId'] ?? null),
            reason: $this->stringOrNull($payload['reason'] ?? $payload['motif'] ?? null),
            occurredAt: new DateTimeImmutable,
            raw: $payload,
        );
    }

    public function fetchStatus(string $messageId): DispatchResult
    {
        $path = str_replace('{id}', rawurlencode($messageId), $this->config->path('status', '/invoices/{id}'));

        $response = $this->exchange(fn () => $this->request()->get($path));
        $payload = $this->payload($response);

        return new DispatchResult(
            status: $this->mapStatus($this->stringOrNull($payload['status'] ?? $payload['statut'] ?? null)),
            network: $this->key,
            messageId: $messageId,
            reason: $this->stringOrNull($payload['reason'] ?? $payload['motif'] ?? null),
            occurredAt: new DateTimeImmutable,
            raw: $payload,
        );
    }

    public function receive(): array
    {
        $response = $this->exchange(fn () => $this->request()->get($this->config->path('inbound', '/inbox')));

        return $this->inboundDocuments($this->payloadList($response, 'invoices'), function (array $item): InboundDocument {
            $messageId = $this->stringOrNull($item['id'] ?? null) ?? '';
            $contents = $this->decodeInbound($item['content'] ?? null, $messageId);

            return new InboundDocument(
                network: $this->key,
                messageId: $messageId,
                format: $this->inboundFormat($item, $messageId, $contents),
                contents: $contents,
                senderId: $this->stringOrNull($item['supplier'] ?? null),
                receivedAt: new DateTimeImmutable,
                raw: $item,
            );
        });
    }

    public function capabilities(): NetworkCapabilities
    {
        $countries = $this->config->stringList('countries');

        return new NetworkCapabilities(
            network: $this->key,
            formats: [Format::Ubl, Format::Cii],
            countries: $countries === [] ? ['FR'] : $countries,
            canSend: true,
            canFetchStatus: true,
            canReceive: true,
        );
    }

    /**
     * @return array<string, string|null>
     */
    private function frenchIdentifier(Party $party): array
    {
        return [
            'name' => $party->name,
            'siret' => $party->legalRegistrationId,
            'vat' => $party->vatId,
            'country' => $party->countryCode,
        ];
    }
}
