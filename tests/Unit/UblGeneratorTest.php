<?php

declare(strict_types=1);

use Vimatech\EInvoicing\Dtos\CanonicalInvoice;
use Vimatech\EInvoicing\Dtos\PrecedingInvoiceReference;
use Vimatech\EInvoicing\Enums\Format;
use Vimatech\EInvoicing\Formats\UblGenerator;
use Vimatech\EInvoicing\Tests\Support\InvoiceFactory;

function ublNamespaces(DOMXPath $xpath): void
{
    $xpath->registerNamespace('ubl', 'urn:oasis:names:specification:ubl:schema:xsd:Invoice-2');
    $xpath->registerNamespace('cn', 'urn:oasis:names:specification:ubl:schema:xsd:CreditNote-2');
    $xpath->registerNamespace('cac', 'urn:oasis:names:specification:ubl:schema:xsd:CommonAggregateComponents-2');
    $xpath->registerNamespace('cbc', 'urn:oasis:names:specification:ubl:schema:xsd:CommonBasicComponents-2');
}

it('matches the Peppol BIS 3.0 invoice golden file', function () {
    $document = (new UblGenerator)->generate(InvoiceFactory::standardInvoice());

    $golden = file_get_contents(__DIR__.'/../fixtures/peppol/invoice.xml');

    expect($document->contents)->toBe($golden)
        ->and($document->format)->toBe(Format::Ubl)
        ->and($document->profile)->toBe(UblGenerator::CUSTOMIZATION_ID)
        ->and($document->filename)->toBe('INV-2024-0001.xml');
});

it('matches the Peppol BIS 3.0 credit note golden file', function () {
    $document = (new UblGenerator)->generate(InvoiceFactory::creditNote());

    $golden = file_get_contents(__DIR__.'/../fixtures/peppol/credit-note.xml');

    expect($document->contents)->toBe($golden);
});

it('produces well-formed XML with the correct Peppol identifiers', function () {
    $xml = (new UblGenerator)->generate(InvoiceFactory::standardInvoice())->contents;

    $dom = new DOMDocument;
    expect($dom->loadXML($xml))->toBeTrue();

    $xpath = new DOMXPath($dom);
    ublNamespaces($xpath);

    expect($xpath->evaluate('string(/ubl:Invoice/cbc:CustomizationID)'))
        ->toBe(UblGenerator::CUSTOMIZATION_ID)
        ->and($xpath->evaluate('string(/ubl:Invoice/cbc:ProfileID)'))
        ->toBe(UblGenerator::PROFILE_ID)
        ->and($xpath->evaluate('string(/ubl:Invoice/cbc:InvoiceTypeCode)'))
        ->toBe('380');
});

it('emits the document totals that balance', function () {
    $xml = (new UblGenerator)->generate(InvoiceFactory::standardInvoice())->contents;

    $dom = new DOMDocument;
    $dom->loadXML($xml);
    $xpath = new DOMXPath($dom);
    ublNamespaces($xpath);

    $base = '/ubl:Invoice/cac:LegalMonetaryTotal/';

    expect($xpath->evaluate("string({$base}cbc:LineExtensionAmount)"))->toBe('1250.00')
        ->and($xpath->evaluate("string({$base}cbc:TaxInclusiveAmount)"))->toBe('1512.50')
        ->and($xpath->evaluate("string({$base}cbc:PayableAmount)"))->toBe('1512.50')
        ->and($xpath->evaluate('string(/ubl:Invoice/cac:TaxTotal/cbc:TaxAmount)'))->toBe('262.50');
});

it('uses CreditNote root and credited quantity for credit notes', function () {
    $xml = (new UblGenerator)->generate(InvoiceFactory::creditNote())->contents;

    $dom = new DOMDocument;
    $dom->loadXML($xml);
    $xpath = new DOMXPath($dom);
    ublNamespaces($xpath);

    expect($xpath->evaluate('string(/cn:CreditNote/cbc:CreditNoteTypeCode)'))->toBe('381')
        ->and($xpath->evaluate('count(/cn:CreditNote/cac:CreditNoteLine)'))->toBe(1.0)
        ->and($xpath->evaluate('count(//cbc:CreditedQuantity)'))->toBe(1.0);
});

it('escapes special characters in text nodes', function () {
    $base = InvoiceFactory::standardInvoice();
    $invoice = new CanonicalInvoice(
        number: $base->number,
        issueDate: $base->issueDate,
        currency: $base->currency,
        seller: $base->seller,
        buyer: $base->buyer,
        lines: $base->lines,
        taxBreakdowns: $base->taxBreakdowns,
        buyerReference: $base->buyerReference,
        note: 'Fish & Chips < > "quoted"',
    );

    $xml = (new UblGenerator)->generate($invoice)->contents;

    expect($xml)->toContain('Fish &amp; Chips &lt; &gt;');

    $dom = new DOMDocument;
    expect($dom->loadXML($xml))->toBeTrue();
});

it('emits the preceding invoice reference on a credit note', function () {
    $xml = (new UblGenerator)->generate(InvoiceFactory::creditNoteCorrecting())->contents;

    $dom = new DOMDocument;
    $dom->loadXML($xml);
    $xpath = new DOMXPath($dom);
    ublNamespaces($xpath);

    $reference = '/cn:CreditNote/cac:BillingReference/cac:InvoiceDocumentReference/';

    expect($xpath->evaluate("string({$reference}cbc:ID)"))->toBe('INV-2024-0001')
        ->and($xpath->evaluate("string({$reference}cbc:IssueDate)"))->toBe('2024-01-15');
});

it('emits the preceding invoice reference on a 380 invoice as well', function () {
    $base = InvoiceFactory::standardInvoice();
    $invoice = new CanonicalInvoice(
        number: $base->number,
        issueDate: $base->issueDate,
        currency: $base->currency,
        seller: $base->seller,
        buyer: $base->buyer,
        lines: $base->lines,
        taxBreakdowns: $base->taxBreakdowns,
        buyerReference: $base->buyerReference,
        orderReference: $base->orderReference,
        precedingInvoiceReference: new PrecedingInvoiceReference('PREPAY-7'),
    );

    $xml = (new UblGenerator)->generate($invoice)->contents;

    $dom = new DOMDocument;
    $dom->loadXML($xml);
    $xpath = new DOMXPath($dom);
    ublNamespaces($xpath);

    $reference = '/ubl:Invoice/cac:BillingReference/cac:InvoiceDocumentReference/';

    expect($xpath->evaluate("string({$reference}cbc:ID)"))->toBe('PREPAY-7')
        ->and($xpath->evaluate("count({$reference}cbc:IssueDate)"))->toBe(0.0);
});

it('sequences BillingReference after OrderReference and before the supplier party', function () {
    $base = InvoiceFactory::standardInvoice();
    $invoice = new CanonicalInvoice(
        number: $base->number,
        issueDate: $base->issueDate,
        currency: $base->currency,
        seller: $base->seller,
        buyer: $base->buyer,
        lines: $base->lines,
        taxBreakdowns: $base->taxBreakdowns,
        buyerReference: $base->buyerReference,
        orderReference: $base->orderReference,
        precedingInvoiceReference: new PrecedingInvoiceReference('INV-1'),
    );

    $xml = (new UblGenerator)->generate($invoice)->contents;

    $dom = new DOMDocument;
    $dom->loadXML($xml);
    $xpath = new DOMXPath($dom);
    ublNamespaces($xpath);

    $billingReference = '/ubl:Invoice/cac:BillingReference';

    expect($xpath->evaluate("count({$billingReference}/preceding-sibling::cac:OrderReference)"))->toBe(1.0)
        ->and($xpath->evaluate("count({$billingReference}/following-sibling::cac:AccountingSupplierParty)"))->toBe(1.0);
});

it('omits BillingReference when no preceding invoice is referenced', function () {
    $xml = (new UblGenerator)->generate(InvoiceFactory::creditNote())->contents;

    expect($xml)->not->toContain('BillingReference');
});
