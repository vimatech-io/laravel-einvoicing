<?php

declare(strict_types=1);

namespace Vimatech\EInvoicing\Exceptions;

use Vimatech\EInvoicing\Enums\Format;

final class UnrecognisedInboundDocument extends EInvoicingException
{
    /**
     * @param  array<string, mixed>  $raw  The provider's entry for the document, as received.
     */
    private function __construct(
        string $message,
        public readonly string $network,
        public readonly string $messageId,
        public readonly ?string $declaredFormat,
        public readonly array $raw,
    ) {
        parent::__construct($message);
    }

    /**
     * @param  array<string, mixed>  $raw
     */
    public static function from(string $network, string $messageId, ?string $declaredFormat, array $raw): self
    {
        $document = $messageId === '' ? 'a document' : "document \"{$messageId}\"";
        $declared = $declaredFormat === null
            ? 'no format was declared'
            : sprintf('the declared format "%s" is not one of %s', $declaredFormat, implode(', ', array_column(Format::cases(), 'value')));

        return new self(
            "The \"{$network}\" network returned {$document} whose format cannot be determined: {$declared}, "
            .'and the contents are neither a PDF nor a UBL Invoice, UBL CreditNote or CII CrossIndustryInvoice XML document.',
            $network,
            $messageId,
            $declaredFormat,
            $raw,
        );
    }

    /**
     * @return array<string, string|null>
     */
    public function context(): array
    {
        return [
            'network' => $this->network,
            'messageId' => $this->messageId,
            'declaredFormat' => $this->declaredFormat,
        ];
    }
}
