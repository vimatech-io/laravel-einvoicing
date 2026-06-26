<?php

declare(strict_types=1);

namespace Vimatech\EInvoicing\Dtos;

use DateTimeImmutable;
use Vimatech\EInvoicing\Enums\Format;

/**
 * A document retrieved from a network's inbox via EInvoiceNetwork::receive().
 */
final readonly class InboundDocument
{
    /**
     * @param  string  $network  Network the document was received from.
     * @param  string  $messageId  Provider message identifier.
     * @param  Format  $format  Detected syntax of the payload.
     * @param  string  $contents  Raw payload (e.g. XML string).
     * @param  string|null  $senderId  Electronic address of the sender, when known.
     * @param  DateTimeImmutable|null  $receivedAt  When the network received the document.
     * @param  array<string, mixed>  $raw  Untouched provider payload for auditing.
     */
    public function __construct(
        public string $network,
        public string $messageId,
        public Format $format,
        public string $contents,
        public ?string $senderId = null,
        public ?DateTimeImmutable $receivedAt = null,
        public array $raw = [],
    ) {}
}
