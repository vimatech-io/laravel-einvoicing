<?php

declare(strict_types=1);

use Vimatech\EInvoicing\Contracts\FormatGenerator;
use Vimatech\EInvoicing\Dtos\GeneratedDocument;
use Vimatech\EInvoicing\Enums\Format;
use Vimatech\EInvoicing\Facades\EInvoice;
use Vimatech\EInvoicing\Formats\CiiGenerator;
use Vimatech\EInvoicing\Formats\UblGenerator;
use Vimatech\EInvoicing\Networks\NullDriver;
use Vimatech\EInvoicing\Tests\Support\InvoiceFactory;

it('resolves a format generator and renders through the facade', function () {
    $generator = EInvoice::format(Format::Ubl);

    expect($generator)->toBeInstanceOf(FormatGenerator::class)
        ->and($generator)->toBeInstanceOf(UblGenerator::class);

    $document = $generator->generate(InvoiceFactory::standardInvoice());

    expect($document)->toBeInstanceOf(GeneratedDocument::class)
        ->and($document->format)->toBe(Format::Ubl);
});

it('selects the generator per requested format', function () {
    expect(EInvoice::format(Format::Cii))->toBeInstanceOf(CiiGenerator::class);
});

it('generates with the configured default format', function () {
    $document = EInvoice::generate(InvoiceFactory::standardInvoice());

    expect($document->format)->toBe(Format::Ubl);
});

it('resolves a network by key', function () {
    config()->set('einvoicing.networks.sandbox', ['driver' => 'null']);

    expect(EInvoice::network('sandbox'))->toBeInstanceOf(NullDriver::class);
});

it('routes by country through the facade', function () {
    expect(EInvoice::route('BE')->key())->toBe('peppol');
});
