<?php

declare(strict_types=1);

use Vimatech\EInvoicing\Dtos\CanonicalInvoice;
use Vimatech\EInvoicing\Dtos\PrecedingInvoiceReference;
use Vimatech\EInvoicing\Enums\Format;
use Vimatech\EInvoicing\Formats\CiiGenerator;
use Vimatech\EInvoicing\Tests\Support\InvoiceFactory;

function ciiNamespaces(DOMXPath $xpath): void
{
    $xpath->registerNamespace('rsm', 'urn:un:unece:uncefact:data:standard:CrossIndustryInvoice:100');
    $xpath->registerNamespace('ram', 'urn:un:unece:uncefact:data:standard:ReusableAggregateBusinessInformationEntity:100');
    $xpath->registerNamespace('qdt', 'urn:un:unece:uncefact:data:standard:QualifiedDataType:100');
}

it('produces well-formed EN 16931 CII', function () {
    $document = (new CiiGenerator)->generate(InvoiceFactory::standardInvoice());

    expect($document->format)->toBe(Format::Cii)
        ->and($document->profile)->toBe(CiiGenerator::GUIDELINE_ID)
        ->and($document->filename)->toBe('INV-2024-0001-cii.xml');

    $dom = new DOMDocument;
    expect($dom->loadXML($document->contents))->toBeTrue();
});

it('carries the EN 16931 guideline identifier and totals', function () {
    $xml = (new CiiGenerator)->generate(InvoiceFactory::standardInvoice())->contents;

    $dom = new DOMDocument;
    $dom->loadXML($xml);
    $xpath = new DOMXPath($dom);
    ciiNamespaces($xpath);

    expect($xpath->evaluate('string(//ram:GuidelineSpecifiedDocumentContextParameter/ram:ID)'))
        ->toBe(CiiGenerator::GUIDELINE_ID)
        ->and($xpath->evaluate('string(//ram:SpecifiedTradeSettlementHeaderMonetarySummation/ram:DuePayableAmount)'))
        ->toBe('1512.50')
        ->and($xpath->evaluate('count(//ram:IncludedSupplyChainTradeLineItem)'))
        ->toBe(2.0);
});

it('emits the preceding invoice reference after the monetary summation', function () {
    $xml = (new CiiGenerator)->generate(InvoiceFactory::creditNoteCorrecting())->contents;

    $dom = new DOMDocument;
    $dom->loadXML($xml);
    $xpath = new DOMXPath($dom);
    ciiNamespaces($xpath);

    $referenced = '//ram:ApplicableHeaderTradeSettlement/ram:InvoiceReferencedDocument';

    expect($xpath->evaluate("string({$referenced}/ram:IssuerAssignedID)"))
        ->toBe('INV-2024-0001')
        ->and($xpath->evaluate("string({$referenced}/ram:FormattedIssueDateTime/qdt:DateTimeString)"))
        ->toBe('20240115')
        ->and($xpath->evaluate("string({$referenced}/ram:FormattedIssueDateTime/qdt:DateTimeString/@format)"))
        ->toBe('102')
        ->and($xpath->evaluate("count({$referenced}/preceding-sibling::ram:SpecifiedTradeSettlementHeaderMonetarySummation)"))
        ->toBe(1.0);
});

it('omits the issue date when the preceding reference carries none', function () {
    $creditNote = InvoiceFactory::creditNoteCorrecting();
    $invoice = new CanonicalInvoice(
        number: $creditNote->number,
        issueDate: $creditNote->issueDate,
        currency: $creditNote->currency,
        seller: $creditNote->seller,
        buyer: $creditNote->buyer,
        lines: $creditNote->lines,
        taxBreakdowns: $creditNote->taxBreakdowns,
        typeCode: $creditNote->typeCode,
        buyerReference: $creditNote->buyerReference,
        precedingInvoiceReference: new PrecedingInvoiceReference('INV-2024-0001'),
    );

    $xml = (new CiiGenerator)->generate($invoice)->contents;

    $dom = new DOMDocument;
    $dom->loadXML($xml);
    $xpath = new DOMXPath($dom);
    ciiNamespaces($xpath);

    expect($xpath->evaluate('string(//ram:InvoiceReferencedDocument/ram:IssuerAssignedID)'))
        ->toBe('INV-2024-0001')
        ->and($xpath->evaluate('count(//ram:InvoiceReferencedDocument/ram:FormattedIssueDateTime)'))
        ->toBe(0.0);
});

it('omits InvoiceReferencedDocument when no preceding invoice is referenced', function () {
    $xml = (new CiiGenerator)->generate(InvoiceFactory::creditNote())->contents;

    expect($xml)->not->toContain('InvoiceReferencedDocument');
});
