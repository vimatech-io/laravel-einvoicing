<?php

declare(strict_types=1);

use Vimatech\EInvoicing\Dtos\CanonicalInvoice;
use Vimatech\EInvoicing\Dtos\LineItem;
use Vimatech\EInvoicing\Dtos\Party;
use Vimatech\EInvoicing\Dtos\TaxBreakdown;
use Vimatech\EInvoicing\Exceptions\InvalidInvoice;
use Vimatech\EInvoicing\Formats\Support\InvoiceValidator;
use Vimatech\EInvoicing\Formats\UblGenerator;
use Vimatech\EInvoicing\Tests\Support\InvoiceFactory;

function invoiceWith(array $overrides): CanonicalInvoice
{
    $base = InvoiceFactory::standardInvoice();

    return new CanonicalInvoice(
        number: $overrides['number'] ?? $base->number,
        issueDate: $base->issueDate,
        currency: $overrides['currency'] ?? $base->currency,
        seller: $overrides['seller'] ?? $base->seller,
        buyer: $overrides['buyer'] ?? $base->buyer,
        lines: $overrides['lines'] ?? $base->lines,
        taxBreakdowns: $overrides['taxBreakdowns'] ?? $base->taxBreakdowns,
        buyerReference: array_key_exists('buyerReference', $overrides) ? $overrides['buyerReference'] : $base->buyerReference,
        orderReference: array_key_exists('orderReference', $overrides) ? $overrides['orderReference'] : null,
    );
}

it('accepts a complete invoice', function () {
    InvoiceValidator::assert(InvoiceFactory::standardInvoice());
})->throwsNoExceptions();

it('rejects a missing invoice number', function () {
    expect(fn () => InvoiceValidator::assert(invoiceWith(['number' => ' '])))
        ->toThrow(InvalidInvoice::class);
});

it('collects every violation rather than failing on the first', function () {
    $invoice = invoiceWith([
        'number' => '',
        'currency' => 'EURO',
        'buyerReference' => null,
        'orderReference' => null,
    ]);

    $violations = (new InvoiceValidator)->collect($invoice);

    expect($violations)->toHaveCount(3)
        ->and(implode(' ', $violations))->toContain('BT-1')
        ->and(implode(' ', $violations))->toContain('BT-5')
        ->and(implode(' ', $violations))->toContain('BR-AB');
});

it('requires either a buyer reference or an order reference', function () {
    expect(fn () => InvoiceValidator::assert(invoiceWith(['buyerReference' => null, 'orderReference' => null])))
        ->toThrow(InvalidInvoice::class, 'BR-AB');
});

it('requires a seller VAT id for standard-rated VAT', function () {
    $seller = new Party(name: 'No VAT Ltd', countryCode: 'BE', endpointId: '1', endpointScheme: '0208');

    $violations = (new InvoiceValidator)->collect(invoiceWith(['seller' => $seller]));

    expect(implode(' ', $violations))->toContain('BR-S-02');
});

it('detects an inconsistent line net amount', function () {
    $lines = [new LineItem(
        id: '1',
        name: 'Item',
        quantity: 2.0,
        netPrice: 10.0,
        lineExtensionAmount: 999.0,
        taxCategory: 'S',
        taxPercent: 21.0,
    )];

    $violations = (new InvoiceValidator)->collect(invoiceWith([
        'lines' => $lines,
        'taxBreakdowns' => [new TaxBreakdown('S', 21.0, 999.0, 209.79)],
    ]));

    expect(implode(' ', $violations))->toContain('BT-131');
});

it('detects a VAT amount that does not match the rate', function () {
    $violations = (new InvoiceValidator)->collect(invoiceWith([
        'lines' => [new LineItem('1', 'Item', 1.0, 100.0, 100.0, 'S', 21.0)],
        'taxBreakdowns' => [new TaxBreakdown('S', 21.0, 100.0, 50.0)],
    ]));

    expect(implode(' ', $violations))->toContain('BR-CO-17');
});

it('requires an exemption reason for exempt VAT', function () {
    $violations = (new InvoiceValidator)->collect(invoiceWith([
        'lines' => [new LineItem('1', 'Item', 1.0, 100.0, 100.0, 'E', 0.0)],
        'taxBreakdowns' => [new TaxBreakdown('E', 0.0, 100.0, 0.0)],
    ]));

    expect(implode(' ', $violations))->toContain('exemption reason');
});

it('requires electronic addresses when generating UBL for Peppol', function () {
    $seller = new Party(name: 'Seller', countryCode: 'BE', vatId: 'BE0123456789');
    $buyer = new Party(name: 'Buyer', countryCode: 'BE');

    expect(fn () => (new UblGenerator)->generate(invoiceWith(['seller' => $seller, 'buyer' => $buyer])))
        ->toThrow(InvalidInvoice::class, 'electronic address');
});

it('exposes violations on the exception', function () {
    try {
        InvoiceValidator::assert(invoiceWith(['number' => '']));
        $this->fail('expected InvalidInvoice');
    } catch (InvalidInvoice $e) {
        expect($e->violations())->not->toBeEmpty();
    }
});
