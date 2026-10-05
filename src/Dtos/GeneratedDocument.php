<?php

declare(strict_types=1);

namespace Vimatech\EInvoicing\Dtos;

use DOMElement;
use DOMXPath;
use Vimatech\EInvoicing\Enums\Format;
use Vimatech\EInvoicing\Exceptions\EInvoicingException;
use Vimatech\EInvoicing\Exceptions\NotImplemented;
use Vimatech\EInvoicing\Formats\CiiGenerator;
use Vimatech\EInvoicing\Formats\Support\Xml;
use Vimatech\EInvoicing\Formats\UblGenerator;

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
     * Rebuild the document generate() produced from its stored payload, so the
     * exact bytes issued can be transmitted without rendering the invoice again.
     *
     * @throws EInvoicingException when the contents are not a document of this format for this invoice
     * @throws NotImplemented for a format the package cannot generate
     */
    public static function fromStored(Format $format, string $contents, string $invoiceNumber): self
    {
        $storedNumber = self::documentNumber($format, $contents);

        if ($storedNumber !== $invoiceNumber) {
            throw new EInvoicingException(sprintf(
                'The stored %s document identifies invoice "%s", not "%s"; it cannot be transmitted as that invoice.',
                $format->value,
                $storedNumber,
                $invoiceNumber,
            ));
        }

        return new self(
            format: $format,
            contents: $contents,
            mimeType: $format->mimeType(),
            filename: $format->filename($invoiceNumber),
            profile: $format->profile(),
            invoiceNumber: $invoiceNumber,
        );
    }

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

    private static function documentNumber(Format $format, string $contents): string
    {
        [$roots, $number] = match ($format) {
            Format::Ubl => [[UblGenerator::INVOICE_NS, UblGenerator::CREDIT_NOTE_NS], '/*/cbc:ID'],
            Format::Cii => [[CiiGenerator::RSM], '/rsm:CrossIndustryInvoice/rsm:ExchangedDocument/ram:ID'],
            Format::FacturX => throw NotImplemented::format($format->value),
        };

        $dom = Xml::parse($contents);
        $identifiers = false;

        if ($dom !== null) {
            $xpath = new DOMXPath($dom);
            $xpath->registerNamespace('cbc', UblGenerator::CBC);
            $xpath->registerNamespace('rsm', CiiGenerator::RSM);
            $xpath->registerNamespace('ram', CiiGenerator::RAM);
            $identifiers = $xpath->query($number);
        }

        $identifier = $identifiers !== false && $identifiers->length === 1 ? $identifiers->item(0) : null;

        if (! $identifier instanceof DOMElement
            || ! in_array($dom?->documentElement?->namespaceURI, $roots, true)) {
            throw new EInvoicingException("The stored contents are not a {$format->value} document this package generates.");
        }

        return $identifier->textContent;
    }
}
