<?php

declare(strict_types=1);

use Vimatech\EInvoicing\Dtos\CanonicalInvoice;
use Vimatech\EInvoicing\Dtos\LineItem;
use Vimatech\EInvoicing\Dtos\TaxBreakdown;
use Vimatech\EInvoicing\Enums\Format;
use Vimatech\EInvoicing\Facades\EInvoice;
use Vimatech\EInvoicing\Routing\EInvoiceRouter;
use Vimatech\EInvoicing\Tests\Support\InvoiceFactory;

function invoiceNumbered(string $number): CanonicalInvoice
{
    return new CanonicalInvoice(
        number: $number,
        issueDate: new DateTimeImmutable('2024-01-15'),
        currency: 'EUR',
        seller: InvoiceFactory::seller(),
        buyer: InvoiceFactory::buyer(),
        lines: [new LineItem(
            id: '1',
            name: 'Consulting',
            quantity: 1.0,
            netPrice: 100.0,
            lineExtensionAmount: 100.0,
            taxCategory: 'S',
            taxPercent: 21.0,
        )],
        taxBreakdowns: [new TaxBreakdown(category: 'S', percent: 21.0, taxableAmount: 100.0, taxAmount: 21.0)],
        buyerReference: 'PO-1',
    );
}

it('carries nothing from one request into the next through the memoised generator', function () {
    $generator = EInvoice::format(Format::Ubl);
    $first = EInvoice::generate(invoiceNumbered('REQ-1-INV'));

    // Second request on the same worker: same memoised generator instance.
    expect(EInvoice::format(Format::Ubl))->toBe($generator);

    $second = EInvoice::generate(invoiceNumbered('REQ-2-INV'));

    expect($second->contents)->toContain('REQ-2-INV')
        ->and($second->contents)->not->toContain('REQ-1-INV')
        ->and($first->contents)->toContain('REQ-1-INV')
        ->and($second->invoiceNumber)->toBe('REQ-2-INV')
        ->and(strlen($second->contents))->toBe(strlen($first->contents));
});

it('carries nothing from one request into the next through the memoised network driver', function () {
    $fake = EInvoice::fake('peppol');

    EInvoice::send(invoiceNumbered('REQ-1-INV'), networkKey: 'peppol');
    expect(EInvoice::network('peppol'))->toBe($fake);

    EInvoice::send(invoiceNumbered('REQ-2-INV'), networkKey: 'peppol');

    expect($fake->lastSent()?->number)->toBe('REQ-2-INV')
        ->and($fake->sentCount())->toBe(2);
});

it('keeps a routing override for the life of the worker, not the request', function () {
    app(EInvoiceRouter::class)->overrideUsing(fn (string $country): ?string => 'sandbox');

    expect(EInvoice::route('BE')->key())->toBe('sandbox')
        ->and(EInvoice::route('BE')->key())->toBe('sandbox');
})->note('Register the override in a service provider. Registering it per request leaks it to every later request on the same worker.');
