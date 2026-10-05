<?php

declare(strict_types=1);

namespace Vimatech\EInvoicing\Networks;

use Closure;
use Illuminate\Contracts\Debug\ExceptionHandler;
use Illuminate\Http\Client\Factory as HttpFactory;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Throwable;
use Vimatech\EInvoicing\Contracts\EInvoiceNetwork;
use Vimatech\EInvoicing\Dtos\InboundDocument;
use Vimatech\EInvoicing\Enums\Format;
use Vimatech\EInvoicing\Enums\LifecycleStatus;
use Vimatech\EInvoicing\Exceptions\EInvoicingException;
use Vimatech\EInvoicing\Exceptions\InvalidDriverConfig;
use Vimatech\EInvoicing\Exceptions\NetworkException;
use Vimatech\EInvoicing\Exceptions\UnrecognisedInboundDocument;
use Vimatech\EInvoicing\Formats\CiiGenerator;
use Vimatech\EInvoicing\Formats\Support\Xml;
use Vimatech\EInvoicing\Formats\UblGenerator;

/**
 * Shared HTTP plumbing for REST-based partner networks.
 *
 * Config keys: base_url, token, auth, timeout, headers, verify, status_map, paths.
 */
abstract class AbstractHttpDriver implements EInvoiceNetwork
{
    protected readonly DriverConfig $config;

    /**
     * @param  array<string, mixed>  $config
     */
    public function __construct(
        protected readonly HttpFactory $http,
        array $config,
        protected readonly string $key,
        private readonly ?ExceptionHandler $exceptions = null,
    ) {
        $this->config = new DriverConfig($key, $config);
    }

    public function key(): string
    {
        return $this->key;
    }

    protected function request(): PendingRequest
    {
        $baseUrl = $this->config->string('base_url');

        if ($baseUrl === '') {
            throw InvalidDriverConfig::missing($this->key, 'base_url', 'Set it to the partner API root before dispatching.');
        }

        $request = $this->http
            ->baseUrl(rtrim($baseUrl, '/'))
            ->timeout($this->config->int('timeout', 30))
            ->acceptJson()
            ->asJson()
            ->withHeaders($this->config->map('headers'));

        if ($this->config->bool('verify', true) === false) {
            $request = $request->withoutVerifying();
        }

        return $this->authenticate($request);
    }

    /**
     * Refuses rather than letting an unauthenticated request reach an accredited
     * platform, where it is rejected without a usable reason.
     */
    private function authenticate(PendingRequest $request): PendingRequest
    {
        $mode = $this->config->string('auth', 'token');
        $token = $this->config->string('token');

        if ($mode !== 'token' && $mode !== 'none') {
            throw InvalidDriverConfig::expected($this->key, 'auth', 'either "token" or "none"', $mode);
        }

        if ($mode === 'none') {
            if ($token !== '') {
                throw InvalidDriverConfig::contradiction($this->key, 'a token is configured while "auth" is "none". Remove one of the two.');
            }

            return $request;
        }

        if ($token === '') {
            throw InvalidDriverConfig::missing($this->key, 'token', 'Set it, or set "auth" => "none" when the partner authenticates another way (mutual TLS, a signed header).');
        }

        return $request->withToken($token);
    }

    /**
     * A misconfiguration raised while building the request is not a transport
     * failure and must not be presented as one; retrying it never succeeds.
     *
     * @param  Closure(): Response  $exchange
     *
     * @throws NetworkException
     */
    protected function exchange(Closure $exchange): Response
    {
        try {
            return $exchange()->throw();
        } catch (EInvoicingException $e) {
            throw $e;
        } catch (Throwable $e) {
            throw NetworkException::transport($this->key, $e->getMessage(), $e);
        }
    }

    /**
     * Override in a driver whose partner returns an unencoded body; never widen
     * this to accept both, which cannot be told apart and silently yields a
     * corrupt invoice.
     *
     * @throws NetworkException
     */
    protected function decodeInbound(mixed $value, string $messageId): string
    {
        if (! is_string($value) || $value === '') {
            throw NetworkException::malformedInbound($this->key, $messageId, 'the document body is absent');
        }

        $decoded = base64_decode($value, true);

        if ($decoded === false) {
            throw NetworkException::malformedInbound($this->key, $messageId, 'the document body is not valid base64');
        }

        if ($decoded === '') {
            throw NetworkException::malformedInbound($this->key, $messageId, 'the document body decodes to an empty payload');
        }

        return $decoded;
    }

    /**
     * One document whose format cannot be established is reported and left
     * out, so it never blocks the documents behind it in the inbox. Without an
     * exception handler to report to, it stops the batch instead.
     *
     * @param  list<array<string, mixed>>  $items
     * @param  Closure(array<string, mixed>): InboundDocument  $toDocument
     * @return list<InboundDocument>
     *
     * @throws NetworkException
     * @throws UnrecognisedInboundDocument
     */
    protected function inboundDocuments(array $items, Closure $toDocument): array
    {
        $documents = [];

        foreach ($items as $item) {
            try {
                $documents[] = $toDocument($item);
            } catch (UnrecognisedInboundDocument $e) {
                if ($this->exceptions === null) {
                    throw $e;
                }

                $this->exceptions->report($e);
            }
        }

        return $documents;
    }

    /**
     * A recognised declared format wins; otherwise the format is read from the
     * contents, and never assumed.
     *
     * @param  array<string, mixed>  $item
     *
     * @throws UnrecognisedInboundDocument
     */
    protected function inboundFormat(array $item, string $messageId, string $contents): Format
    {
        $declared = $this->stringOrNull($item['format'] ?? null);
        $format = ($declared === null ? null : Format::tryFrom($declared)) ?? $this->detectFormat($contents);

        return $format ?? throw UnrecognisedInboundDocument::from($this->key, $messageId, $declared, $item);
    }

    private function detectFormat(string $contents): ?Format
    {
        if (str_starts_with($contents, '%PDF-')) {
            return Format::FacturX;
        }

        $root = Xml::parse($contents)?->documentElement;

        return match ([$root?->namespaceURI, $root?->localName]) {
            [CiiGenerator::RSM, 'CrossIndustryInvoice'] => Format::Cii,
            [UblGenerator::INVOICE_NS, 'Invoice'], [UblGenerator::CREDIT_NOTE_NS, 'CreditNote'] => Format::Ubl,
            default => null,
        };
    }

    protected function mapStatus(?string $providerStatus): LifecycleStatus
    {
        if ($providerStatus === null) {
            return LifecycleStatus::Unknown;
        }

        $normalised = strtolower($providerStatus);
        $override = $this->config->map('status_map')[$normalised] ?? null;

        if ($override !== null && ($mapped = LifecycleStatus::tryFrom($override)) !== null) {
            return $mapped;
        }

        return LifecycleStatus::tryFrom($normalised) ?? $this->defaultStatusMap($normalised);
    }

    /**
     * @return array<string, mixed>
     */
    protected function payload(Response $response): array
    {
        $json = $response->json();

        if (! is_array($json)) {
            return [];
        }

        $payload = [];
        foreach ($json as $key => $value) {
            $payload[(string) $key] = $value;
        }

        return $payload;
    }

    /**
     * @return list<array<string, mixed>>
     */
    protected function payloadList(Response $response, string $wrapperKey): array
    {
        $json = $response->json($wrapperKey);

        if (! is_array($json)) {
            $json = $response->json();
        }

        if (! is_array($json)) {
            return [];
        }

        $items = [];
        foreach ($json as $item) {
            if (! is_array($item)) {
                continue;
            }

            $normalised = [];
            foreach ($item as $key => $value) {
                $normalised[(string) $key] = $value;
            }

            $items[] = $normalised;
        }

        return $items;
    }

    protected function stringOrNull(mixed $value): ?string
    {
        return is_scalar($value) ? (string) $value : null;
    }

    /** Common partner vocabularies; overridable per driver. */
    protected function defaultStatusMap(string $normalised): LifecycleStatus
    {
        return match ($normalised) {
            'sent', 'queued', 'pending', 'received' => LifecycleStatus::Submitted,
            'transit', 'sending' => LifecycleStatus::InTransit,
            'delivered', 'ok', 'success' => LifecycleStatus::Delivered,
            'accepted', 'approved' => LifecycleStatus::Accepted,
            'rejected', 'refused', 'denied' => LifecycleStatus::Rejected,
            'failed', 'error' => LifecycleStatus::Failed,
            default => LifecycleStatus::Unknown,
        };
    }
}
