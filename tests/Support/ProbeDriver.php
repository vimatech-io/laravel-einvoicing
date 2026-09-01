<?php

declare(strict_types=1);

namespace Vimatech\EInvoicing\Tests\Support;

use DateTimeImmutable;
use Illuminate\Http\Client\PendingRequest;
use ReflectionProperty;
use Vimatech\EInvoicing\Dtos\CanonicalInvoice;
use Vimatech\EInvoicing\Dtos\DispatchResult;
use Vimatech\EInvoicing\Dtos\GeneratedDocument;
use Vimatech\EInvoicing\Dtos\NetworkCapabilities;
use Vimatech\EInvoicing\Enums\Format;
use Vimatech\EInvoicing\Enums\LifecycleStatus;
use Vimatech\EInvoicing\Networks\AbstractHttpDriver;

/**
 * Exposes the request AbstractHttpDriver builds, so config handling can be
 * asserted on the options actually applied rather than on a comment.
 */
final class ProbeDriver extends AbstractHttpDriver
{
    public function pendingRequest(): PendingRequest
    {
        return $this->request();
    }

    /**
     * @return array<string, mixed>
     */
    public function requestOptions(): array
    {
        /** @var array<string, mixed> $options */
        $options = (new ReflectionProperty(PendingRequest::class, 'options'))->getValue($this->pendingRequest());

        return $options;
    }

    public function decode(mixed $value, string $messageId = 'probe-1'): string
    {
        return $this->decodeInbound($value, $messageId);
    }

    public function send(GeneratedDocument $document, CanonicalInvoice $invoice): DispatchResult
    {
        return new DispatchResult(LifecycleStatus::Submitted, $this->key, occurredAt: new DateTimeImmutable);
    }

    public function fetchStatus(string $messageId): DispatchResult
    {
        return new DispatchResult(LifecycleStatus::Unknown, $this->key, messageId: $messageId);
    }

    public function receive(): array
    {
        return [];
    }

    public function capabilities(): NetworkCapabilities
    {
        return new NetworkCapabilities($this->key, formats: [Format::Ubl]);
    }
}
