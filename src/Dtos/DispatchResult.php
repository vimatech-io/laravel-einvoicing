<?php

declare(strict_types=1);

namespace Vimatech\EInvoicing\Dtos;

use DateTimeImmutable;
use Vimatech\EInvoicing\Enums\LifecycleStatus;

/**
 * The outcome of a network operation (send / fetchStatus), normalised across
 * every driver.
 */
final readonly class DispatchResult
{
    /**
     * @param  LifecycleStatus  $status  Canonical lifecycle state.
     * @param  string  $network  Key of the network that produced the result.
     * @param  string|null  $messageId  Provider message identifier, used to poll status.
     * @param  string|null  $transmissionId  Network-level transmission id (e.g. Peppol).
     * @param  string|null  $reason  Human-readable reason, typically for rejections/failures.
     * @param  DateTimeImmutable|null  $occurredAt  When the provider reported this state.
     * @param  array<string, mixed>  $raw  Untouched provider payload for auditing.
     */
    public function __construct(
        public LifecycleStatus $status,
        public string $network,
        public ?string $messageId = null,
        public ?string $transmissionId = null,
        public ?string $reason = null,
        public ?DateTimeImmutable $occurredAt = null,
        public array $raw = [],
    ) {}

    public function successful(): bool
    {
        return $this->status->isSuccessful();
    }

    public function rejected(): bool
    {
        return $this->status === LifecycleStatus::Rejected;
    }
}
