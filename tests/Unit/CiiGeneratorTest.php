<?php

declare(strict_types=1);

use Vimatech\EInvoicing\Enums\Format;
use Vimatech\EInvoicing\Formats\CiiGenerator;
use Vimatech\EInvoicing\Tests\Support\InvoiceFactory;

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
    $xpath->registerNamespace('rsm', 'urn:un:unece:uncefact:data:standard:CrossIndustryInvoice:100');
    $xpath->registerNamespace('ram', 'urn:un:unece:uncefact:data:standard:ReusableAggregateBusinessInformationEntity:100');

    expect($xpath->evaluate('string(//ram:GuidelineSpecifiedDocumentContextParameter/ram:ID)'))
        ->toBe(CiiGenerator::GUIDELINE_ID)
        ->and($xpath->evaluate('string(//ram:SpecifiedTradeSettlementHeaderMonetarySummation/ram:DuePayableAmount)'))
        ->toBe('1512.50')
        ->and($xpath->evaluate('count(//ram:IncludedSupplyChainTradeLineItem)'))
        ->toBe(2.0);
});
