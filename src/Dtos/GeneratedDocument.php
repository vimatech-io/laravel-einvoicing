<?php

declare(strict_types=1);

namespace Vimatech\EInvoicing\Dtos;

use Vimatech\EInvoicing\Enums\Format;
use Vimatech\EInvoicing\Exceptions\EInvoicingException;

/**
 * An immutable, fully-rendered structured document ready to be transmitted or
 * stored.
 */
final readonly class GeneratedDocument
{
    /**
     * @param  Format  $format  The syntax of the rendered payload.
     * @param  string  $contents  The raw rendered payload (e.g. XML string).
     * @param  string  $mimeType  IANA media type of the payload.
     * @param  string  $filename  Suggested file name.
     * @param  string  $profile  Profile / customization identifier the payload conforms to.
     * @param  string  $invoiceNumber  The source invoice number, for traceability.
     */
    public function __construct(
        public Format $format,
        public string $contents,
        public string $mimeType,
        public string $filename,
        public string $profile,
        public string $invoiceNumber,
    ) {}

    /**
     * Raw byte length of the payload.
     */
    public function size(): int
    {
        return strlen($this->contents);
    }

    /**
     * Base64-encoded payload, convenient for JSON network transports.
     */
    public function toBase64(): string
    {
        return base64_encode($this->contents);
    }

    /**
     * Persist the payload to disk and return the bytes written.
     *
     * @throws EInvoicingException when the file cannot be written
     */
    public function save(string $path): int
    {
        $bytes = file_put_contents($path, $this->contents);

        if ($bytes === false) {
            throw new EInvoicingException("Could not write the generated document for invoice \"{$this->invoiceNumber}\" to \"{$path}\".");
        }

        return $bytes;
    }

    public function __toString(): string
    {
        return $this->contents;
    }
}
