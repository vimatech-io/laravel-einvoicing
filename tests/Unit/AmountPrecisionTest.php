<?php

declare(strict_types=1);

use Vimatech\EInvoicing\Dtos\CanonicalInvoice;
use Vimatech\EInvoicing\Dtos\LineItem;
use Vimatech\EInvoicing\Dtos\TaxBreakdown;
use Vimatech\EInvoicing\Exceptions\InvalidInvoice;
use Vimatech\EInvoicing\Formats\CiiGenerator;
use Vimatech\EInvoicing\Formats\Support\Decimal;
use Vimatech\EInvoicing\Formats\Support\InvoiceValidator;
use Vimatech\EInvoicing\Formats\UblGenerator;
use Vimatech\EInvoicing\Tests\Support\InvoiceFactory;

/**
 * @param  list<array{float, float, float}>  $lines  quantity, unit price, net amount
 */
function precisionInvoice(
    array $lines,
    float $percent,
    float $taxAmount,
    string $currency = 'EUR',
    int $amountDecimals = 2,
    float $prepaidAmount = 0.0,
): CanonicalInvoice {
    $base = InvoiceFactory::standardInvoice();
    $items = [];
    $taxable = 0.0;

    foreach ($lines as $index => [$quantity, $price, $amount]) {
        $items[] = new LineItem(
            id: (string) ($index + 1),
            name: 'Item '.($index + 1),
            quantity: $quantity,
            netPrice: $price,
            lineExtensionAmount: $amount,
            taxCategory: 'S',
            taxPercent: $percent,
        );
        $taxable += $amount;
    }

    return new CanonicalInvoice(
        number: 'PREC-1',
        issueDate: $base->issueDate,
        currency: $currency,
        seller: $base->seller,
        buyer: $base->buyer,
        lines: $items,
        taxBreakdowns: [new TaxBreakdown('S', $percent, $taxable, $taxAmount)],
        buyerReference: $base->buyerReference,
        prepaidAmount: $prepaidAmount,
        amountDecimals: $amountDecimals,
    );
}

function xmlValues(string $xml, string $localName): array
{
    $dom = new DOMDocument;
    $dom->loadXML($xml);

    $values = [];
    foreach ((new DOMXPath($dom))->query("//*[local-name()='{$localName}']") ?: [] as $node) {
        $values[] = $node->textContent;
    }

    return $values;
}

it('keeps the unit price (BT-146) at its own precision in UBL', function () {
    $xml = (new UblGenerator)->generate(precisionInvoice([[1000.0, 0.125, 125.0]], 20.0, 25.0))->contents;

    expect(xmlValues($xml, 'PriceAmount'))->toBe(['0.125']);
});

it('keeps the unit price (BT-146) at its own precision in CII', function () {
    $xml = (new CiiGenerator)->generate(precisionInvoice([[1000.0, 0.125, 125.0]], 20.0, 25.0))->contents;

    expect(xmlValues($xml, 'ChargeAmount'))->toBe(['0.125']);
});

it('formats a unit price with at least two and at most six decimals', function () {
    expect(Decimal::unitPrice(200.0))->toBe('200.00')
        ->and(Decimal::unitPrice(0.5))->toBe('0.50')
        ->and(Decimal::unitPrice(0.125))->toBe('0.125')
        ->and(Decimal::unitPrice(1.234567))->toBe('1.234567')
        ->and(fn () => Decimal::unitPrice(1.2345678, 'BT-146'))->toThrow(InvalidInvoice::class, 'BT-146');
});

it('keeps a four-decimal VAT rate in BT-119 and BT-152 in UBL', function () {
    $xml = (new UblGenerator)->generate(precisionInvoice([[1000.0, 0.125, 125.0]], 9.975, 12.47))->contents;

    expect(xmlValues($xml, 'Percent'))->toBe(['9.975', '9.975']);
});

it('keeps a four-decimal VAT rate in BT-119 and BT-152 in CII', function () {
    $xml = (new CiiGenerator)->generate(precisionInvoice([[1000.0, 0.125, 125.0]], 9.975, 12.47))->contents;

    expect(xmlValues($xml, 'RateApplicablePercent'))->toBe(['9.975', '9.975']);
});

it('formats a VAT rate with at least two decimals and refuses a fifth', function () {
    expect(Decimal::percent(21.0))->toBe('21.00')
        ->and(Decimal::percent(5.5))->toBe('5.50')
        ->and(Decimal::percent(9.975))->toBe('9.975')
        ->and(Decimal::percent(14.9975))->toBe('14.9975')
        ->and(fn () => Decimal::percent(9.97512, 'BT-119'))->toThrow(InvalidInvoice::class, 'BT-119');
});

it('refuses to render an amount that two decimals cannot represent', function () {
    $invoice = precisionInvoice([[1.0, 1.235, 1.235]], 20.0, 0.25);

    expect(fn () => (new UblGenerator)->generate($invoice))->toThrow(InvalidInvoice::class, 'VAT breakdown S (BT-116): 1.235')
        ->and(fn () => (new CiiGenerator)->generate($invoice))->toThrow(InvalidInvoice::class, 'Line 1 (BT-131): 1.235');
});

it('names the business term of the amount it refuses', function () {
    $invoice = precisionInvoice([[1.0, 1.0, 1.0]], 20.0, 0.2, prepaidAmount: 0.005);

    expect(fn () => (new UblGenerator)->generate($invoice))->toThrow(InvalidInvoice::class, 'BT-113');
});

it('does not mistake binary float noise for a third decimal', function () {
    $noisy = 0.1 + 0.2;

    expect(Decimal::amount($noisy))->toBe('0.30')
        ->and(Decimal::amount(12345678.91))->toBe('12345678.91')
        ->and(Decimal::percent(0.07 * 100))->toBe('7.00')
        ->and(Decimal::unitPrice(1.1 * 3))->toBe('3.30');

    $xml = (new UblGenerator)->generate(precisionInvoice([[3.0, 0.1, $noisy]], 20.0, 0.06))->contents;

    expect(xmlValues($xml, 'LineExtensionAmount'))->toBe(['0.30', '0.30']);
});

it('computes the totals of a three-decimal currency at three decimals', function () {
    $invoice = precisionInvoice([[1.0, 1.235, 1.235], [1.0, 2.001, 2.001]], 19.0, 0.615, 'TND', 3, prepaidAmount: 1.111);

    expect($invoice->lineExtensionAmount())->toBe(3.236)
        ->and($invoice->taxAmount())->toBe(0.615)
        ->and($invoice->taxInclusiveAmount())->toBe(3.851)
        ->and($invoice->payableAmount())->toBe(2.74);

    InvoiceValidator::assertConformsTo($invoice);
});

it('still refuses a three-decimal document in XML, whose amounts are limited to two decimals', function () {
    $invoice = precisionInvoice([[1.0, 1.235, 1.235]], 19.0, 0.235, 'TND', 3);

    expect(fn () => (new CiiGenerator)->generate($invoice))->toThrow(InvalidInvoice::class, 'BT-131');
});

it('renders a three-decimal currency whose amounts carry no third decimal', function () {
    $invoice = precisionInvoice([[1.0, 1.25, 1.25]], 20.0, 0.25, 'TND', 3);

    expect(xmlValues((new UblGenerator)->generate($invoice)->contents, 'PayableAmount'))->toBe(['1.50']);
});

it('validates and renders a zero-decimal currency at its own scale', function () {
    $invoice = precisionInvoice([[3.0, 33.5, 101.0]], 10.0, 10.0, 'JPY', 0);

    expect($invoice->taxAmount())->toBe(10.0)
        ->and($invoice->payableAmount())->toBe(111.0);

    $xml = (new UblGenerator)->generate($invoice)->contents;

    expect(xmlValues($xml, 'PayableAmount'))->toBe(['111.00'])
        ->and(xmlValues($xml, 'PriceAmount'))->toBe(['33.50']);
});

it('rounds the totals of a zero-decimal currency to whole units', function () {
    $invoice = precisionInvoice([[1.0, 1000.4, 1000.4]], 10.0, 100.0, 'JPY', 0);

    expect($invoice->lineExtensionAmount())->toBe(1000.0);
});

it('scales the arithmetic tolerance to the currency', function () {
    $threeDecimals = precisionInvoice([[1.0, 1.0, 1.0]], 20.0, 0.21, 'TND', 3);

    expect(implode(' ', (new InvoiceValidator)->violations($threeDecimals)))->toContain('BR-CO-17');
});

it('refuses an amount scale that no ISO 4217 currency uses', function (int $decimals) {
    expect(fn () => precisionInvoice([[1.0, 1.0, 1.0]], 20.0, 0.2, amountDecimals: $decimals))
        ->toThrow(InvalidInvoice::class, 'BT-5');
})->with([-1, 5]);
