<?php

declare(strict_types=1);

namespace Vimatech\EInvoicing\Networks;

use Closure;
use Illuminate\Http\Client\Factory as HttpFactory;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Throwable;
use Vimatech\EInvoicing\Contracts\EInvoiceNetwork;
use Vimatech\EInvoicing\Enums\LifecycleStatus;
use Vimatech\EInvoicing\Exceptions\NetworkException;

/**
 * Shared HTTP plumbing for REST-based partner networks.
 *
 * Concrete drivers map their partner's request/response shapes onto the
 * package's neutral DTOs. The HTTP client is Laravel's own
 * (illuminate/http) — no third-party dependency is introduced.
 *
 * Recognised config keys: base_url, token, timeout, headers, verify,
 * status_map and paths (see the published config for details).
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
    ) {
        $this->config = new DriverConfig($config);
    }

    public function key(): string
    {
        return $this->key;
    }

    /**
     * A pre-configured request bound to the partner base URL and credentials.
     */
    protected function request(): PendingRequest
    {
        $baseUrl = $this->config->string('base_url');

        if ($baseUrl === '') {
            throw NetworkException::transport($this->key, 'no base_url is configured');
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

        $token = $this->config->string('token');
        if ($token !== '') {
            $request = $request->withToken($token);
        }

        return $request;
    }

    /**
     * Execute an HTTP exchange, normalising any transport or HTTP-status
     * failure into a single NetworkException so drivers stay free of
     * repetitive try/catch boilerplate.
     *
     * @param  Closure(): Response  $exchange
     *
     * @throws NetworkException
     */
    protected function exchange(Closure $exchange): Response
    {
        try {
            return $exchange()->throw();
        } catch (Throwable $e) {
            throw NetworkException::transport($this->key, $e->getMessage());
        }
    }

    /**
     * Translate a partner-specific status string into a canonical lifecycle
     * status, honouring any per-driver override map from config.
     */
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
     * Decode a JSON object response into a string-keyed array.
     *
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
     * Decode a JSON list response, preferring a wrapper key when present.
     *
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

    /**
     * Built-in mapping for common partner vocabularies; overridable per driver.
     */
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
