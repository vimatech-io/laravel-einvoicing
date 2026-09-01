<?php

declare(strict_types=1);

use Vimatech\EInvoicing\Exceptions\EInvoicingException;
use Vimatech\EInvoicing\Formats\Support\Decimal;
use Vimatech\EInvoicing\Formats\UblGenerator;
use Vimatech\EInvoicing\Tests\Support\InvoiceFactory;

it('refuses to render a non-finite amount instead of emitting "nan"', function () {
    expect(fn () => Decimal::amount(NAN))->toThrow(EInvoicingException::class, 'finite')
        ->and(fn () => Decimal::amount(INF))->toThrow(EInvoicingException::class, 'finite')
        ->and(fn () => Decimal::percent(NAN))->toThrow(EInvoicingException::class, 'finite')
        ->and(fn () => Decimal::quantity(-INF))->toThrow(EInvoicingException::class, 'finite');
});

it('formats finite values exactly as before', function () {
    expect(Decimal::amount(1000.0))->toBe('1000.00')
        ->and(Decimal::percent(21.0))->toBe('21.00')
        ->and(Decimal::quantity(5.0))->toBe('5.00')
        ->and(Decimal::quantity(1.1))->toBe('1.1');
});

it('refuses to write a generated document to an unwritable path', function () {
    $document = (new UblGenerator)->generate(InvoiceFactory::standardInvoice());

    set_error_handler(fn (): bool => true);

    try {
        expect(fn () => $document->save('/no-such-directory-for-tests/invoice.xml'))
            ->toThrow(EInvoicingException::class, 'Could not write');
    } finally {
        restore_error_handler();
    }
});
