<?php

declare(strict_types=1);

use Illuminate\Contracts\Debug\ExceptionHandler;
use Illuminate\Http\Client\Factory as HttpFactory;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Vimatech\EInvoicing\Enums\Format;
use Vimatech\EInvoicing\Events\EInvoiceReceived;
use Vimatech\EInvoicing\Exceptions\NetworkException;
use Vimatech\EInvoicing\Exceptions\UnrecognisedInboundDocument;
use Vimatech\EInvoicing\Facades\EInvoice;
use Vimatech\EInvoicing\Formats\CiiGenerator;
use Vimatech\EInvoicing\Formats\UblGenerator;
use Vimatech\EInvoicing\Networks\FrPdpDriver;
use Vimatech\EInvoicing\Tests\Support\InvoiceFactory;
use Vimatech\EInvoicing\Tests\Support\RecordingExceptionHandler;

const FACTUR_X_PDF = "%PDF-1.7\n%\xE2\xE3\xCF\xD3\n1 0 obj\n<< /Type /Catalog >>\nendobj\n%%EOF\n";
const UNRECOGNISED_XML = '<?xml version="1.0"?><Order xmlns="urn:oasis:names:specification:ubl:schema:xsd:Order-2"><ID>PO-1</ID></Order>';

function frPdpDriver(HttpFactory $http, array $extra = [], ?ExceptionHandler $exceptions = null): FrPdpDriver
{
    return new FrPdpDriver($http, array_merge([
        'base_url' => 'https://pdp.example.test',
        'token' => 'pdp-token',
    ], $extra), 'fr_pdp', $exceptions);
}

function receivedFormat(string $contents, ?string $declared = null): Format
{
    $http = new HttpFactory;
    frPdpInbox($http, [array_filter([
        'id' => 'fr-format',
        'format' => $declared,
        'content' => base64_encode($contents),
    ])]);

    return frPdpDriver($http, [], new RecordingExceptionHandler)->receive()[0]->format;
}

function frPdpInbox(HttpFactory $http, array $invoices): void
{
    $http->fake(['*/inbox' => $http->response(['invoices' => $invoices], 200)]);
}

it('decodes an inbound invoice from the inbox', function () {
    $http = new HttpFactory;
    frPdpInbox($http, [[
        'id' => 'fr-1',
        'format' => 'cii',
        'content' => base64_encode('<CrossIndustryInvoice/>'),
        'supplier' => '12345678900011',
    ]]);

    $documents = frPdpDriver($http)->receive();

    expect($documents)->toHaveCount(1)
        ->and($documents[0]->contents)->toBe('<CrossIndustryInvoice/>')
        ->and($documents[0]->format)->toBe(Format::Cii)
        ->and($documents[0]->senderId)->toBe('12345678900011');
});

it('refuses an inbound invoice whose body is not valid base64', function () {
    $http = new HttpFactory;
    frPdpInbox($http, [[
        'id' => 'fr-2',
        'content' => '<CrossIndustryInvoice>corrupt</CrossIndustryInvoice>',
    ]]);

    expect(fn () => frPdpDriver($http)->receive())
        ->toThrow(NetworkException::class, 'fr-2');
});

it('refuses an inbound invoice with no body at all', function () {
    $http = new HttpFactory;
    frPdpInbox($http, [['id' => 'fr-3', 'format' => 'cii']]);

    expect(fn () => frPdpDriver($http)->receive())
        ->toThrow(NetworkException::class, 'absent');
});

it('stops the whole batch rather than returning the readable part of it', function () {
    $http = new HttpFactory;
    frPdpInbox($http, [
        ['id' => 'fr-4', 'format' => 'cii', 'content' => base64_encode('<CrossIndustryInvoice/>')],
        ['id' => 'fr-5', 'content' => '<CrossIndustryInvoice>corrupt</CrossIndustryInvoice>'],
    ]);

    expect(fn () => frPdpDriver($http)->receive())->toThrow(NetworkException::class, 'fr-5');
});

it('labels an undeclared PDF as Factur-X rather than CII', function () {
    expect(receivedFormat(FACTUR_X_PDF))->toBe(Format::FacturX);
});

it('labels an undeclared CII invoice as CII', function () {
    $cii = (new CiiGenerator)->generate(InvoiceFactory::standardInvoice())->contents;

    expect(receivedFormat($cii))->toBe(Format::Cii);
});

it('labels an undeclared UBL invoice and credit note as UBL', function () {
    $invoice = (new UblGenerator)->generate(InvoiceFactory::standardInvoice())->contents;
    $creditNote = (new UblGenerator)->generate(InvoiceFactory::creditNote())->contents;

    expect(receivedFormat($invoice))->toBe(Format::Ubl)
        ->and(receivedFormat($creditNote))->toBe(Format::Ubl);
});

it('reads the contents when the declared format is not one it knows', function () {
    expect(receivedFormat(FACTUR_X_PDF, 'zugferd'))->toBe(Format::FacturX);
});

it('keeps a recognised declared format over the contents', function () {
    $ubl = (new UblGenerator)->generate(InvoiceFactory::standardInvoice())->contents;

    expect(receivedFormat($ubl, 'ubl'))->toBe(Format::Ubl)
        ->and(receivedFormat(FACTUR_X_PDF, 'cii'))->toBe(Format::Cii);
});

it('does not take an XML root for UBL or CII without its namespace', function () {
    $http = new HttpFactory;
    frPdpInbox($http, [['id' => 'fr-plain', 'content' => base64_encode('<Invoice><ID>1</ID></Invoice>')]]);

    expect(fn () => frPdpDriver($http)->receive())->toThrow(UnrecognisedInboundDocument::class, 'fr-plain');
});

it('reports an unrecognisable document and still returns the others', function () {
    $cii = (new CiiGenerator)->generate(InvoiceFactory::standardInvoice())->contents;
    $ubl = (new UblGenerator)->generate(InvoiceFactory::standardInvoice())->contents;
    $exceptions = new RecordingExceptionHandler;
    $http = new HttpFactory;
    frPdpInbox($http, [
        ['id' => 'fr-cii', 'content' => base64_encode($cii)],
        ['id' => 'fr-unknown', 'format' => 'edifact', 'content' => base64_encode(UNRECOGNISED_XML)],
        ['id' => 'fr-ubl', 'content' => base64_encode($ubl)],
    ]);

    $documents = frPdpDriver($http, [], $exceptions)->receive();

    expect(array_map(fn ($document) => $document->messageId, $documents))->toBe(['fr-cii', 'fr-ubl'])
        ->and($exceptions->reported)->toHaveCount(1)
        ->and($exceptions->reported[0])->toBeInstanceOf(UnrecognisedInboundDocument::class)
        ->and($exceptions->reported[0]->messageId)->toBe('fr-unknown')
        ->and($exceptions->reported[0]->declaredFormat)->toBe('edifact')
        ->and($exceptions->reported[0]->raw['content'])->toBe(base64_encode(UNRECOGNISED_XML))
        ->and($exceptions->reported[0]->getMessage())->toContain('"edifact" is not one of ubl, cii, facturx');
});

it('stops the batch on an unrecognisable document when it has nowhere to report it', function () {
    $http = new HttpFactory;
    frPdpInbox($http, [['id' => 'fr-unknown', 'content' => base64_encode('not an invoice')]]);

    expect(fn () => frPdpDriver($http)->receive())
        ->toThrow(UnrecognisedInboundDocument::class, 'no format was declared');
});

it('reports an unrecognisable document to the application through the manager', function () {
    $exceptions = new RecordingExceptionHandler;
    app()->instance(ExceptionHandler::class, $exceptions);
    config()->set('einvoicing.networks.fr_pdp.base_url', 'https://pdp.example.test');
    config()->set('einvoicing.networks.fr_pdp.token', 'pdp-token');
    Event::fake([EInvoiceReceived::class]);
    Http::fake(['*/inbox' => Http::response(['invoices' => [
        ['id' => 'fr-pdf', 'content' => base64_encode(FACTUR_X_PDF)],
        ['id' => 'fr-unknown', 'content' => base64_encode('not an invoice')],
    ]], 200)]);

    $documents = EInvoice::receive('fr_pdp');

    expect($documents)->toHaveCount(1)
        ->and($documents[0]->format)->toBe(Format::FacturX)
        ->and($exceptions->reported)->toHaveCount(1)
        ->and($exceptions->reported[0]->messageId)->toBe('fr-unknown');
    Event::assertDispatchedTimes(EInvoiceReceived::class, 1);
});

it('does not advertise Factur-X, which the package cannot generate', function () {
    expect(frPdpDriver(new HttpFactory)->capabilities()->supports(Format::FacturX))->toBeFalse()
        ->and(frPdpDriver(new HttpFactory)->capabilities()->formats)->toBe([Format::Ubl, Format::Cii]);
});
