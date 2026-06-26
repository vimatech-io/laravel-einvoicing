<?php

declare(strict_types=1);

use Vimatech\EInvoicing\Exceptions\NotImplemented;
use Vimatech\EInvoicing\Formats\FacturXGenerator;
use Vimatech\EInvoicing\Tests\Support\InvoiceFactory;

it('throws NotImplemented until the PDF/A-3 module ships', function () {
    expect(fn () => (new FacturXGenerator)->generate(InvoiceFactory::standardInvoice()))
        ->toThrow(NotImplemented::class, 'facturx');
});
