<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Event;
use Vimatech\EInvoicing\Dtos\GeneratedDocument;
use Vimatech\EInvoicing\Enums\Format;
use Vimatech\EInvoicing\Enums\LifecycleStatus;
use Vimatech\EInvoicing\Events\EInvoiceDelivered;
use Vimatech\EInvoicing\Events\EInvoiceDispatched;
use Vimatech\EInvoicing\Events\EInvoiceGenerated;
use Vimatech\EInvoicing\Events\EInvoiceRejected;
use Vimatech\EInvoicing\Exceptions\EInvoicingException;
use Vimatech\EInvoicing\Exceptions\NotImplemented;
use Vimatech\EInvoicing\Facades\EInvoice;
use Vimatech\EInvoicing\Formats\CiiGenerator;
use Vimatech\EInvoicing\Formats\UblGenerator;
use Vimatech\EInvoicing\Tests\Support\InvoiceFactory;

it('rebuilds exactly the document generate() produced', function (Format $format, Closure $invoice) {
    $generated = EInvoice::generate($invoice(), $format);

    expect(GeneratedDocument::fromStored($format, $generated->contents, $generated->invoiceNumber))
        ->toEqual($generated);
})->with([
    'UBL invoice' => [Format::Ubl, fn () => InvoiceFactory::standardInvoice()],
    'UBL credit note' => [Format::Ubl, fn () => InvoiceFactory::creditNote()],
    'CII invoice' => [Format::Cii, fn () => InvoiceFactory::standardInvoice()],
]);

it('transmits the stored bytes without rendering the invoice again', function () {
    Event::fake([EInvoiceGenerated::class, EInvoiceDispatched::class, EInvoiceDelivered::class, EInvoiceRejected::class]);
    $fake = EInvoice::fake('peppol');
    $invoice = InvoiceFactory::standardInvoice();
    $stored = (new UblGenerator)->generate($invoice)->contents."<!-- stored at issuance -->\n";

    $result = EInvoice::transmit(GeneratedDocument::fromStored(Format::Ubl, $stored, $invoice->number), $invoice, 'peppol');

    expect($result->status)->toBe(LifecycleStatus::Delivered)
        ->and($fake->sent()[0]['document']->contents)->toBe($stored);
    Event::assertNotDispatched(EInvoiceGenerated::class);
    Event::assertDispatched(EInvoiceDispatched::class, fn (EInvoiceDispatched $event) => $event->document->contents === $stored);
    Event::assertDispatched(EInvoiceDelivered::class);
    Event::assertNotDispatched(EInvoiceRejected::class);
});

it('announces a rejection of a stored document like send() does', function () {
    Event::fake([EInvoiceDispatched::class, EInvoiceDelivered::class, EInvoiceRejected::class]);
    EInvoice::fake('peppol')->alwaysReturn(LifecycleStatus::Rejected);
    $invoice = InvoiceFactory::standardInvoice();

    EInvoice::transmit(EInvoice::generate($invoice), $invoice);

    Event::assertDispatched(EInvoiceDispatched::class);
    Event::assertDispatched(EInvoiceRejected::class);
    Event::assertNotDispatched(EInvoiceDelivered::class);
});

it('refuses to transmit a document under another invoice', function () {
    $fake = EInvoice::fake('peppol');
    $document = EInvoice::generate(InvoiceFactory::standardInvoice());

    expect(fn () => EInvoice::transmit($document, InvoiceFactory::creditNote(), 'peppol'))
        ->toThrow(EInvoicingException::class, 'CN-2024-0001');
    $fake->assertNothingSent();
});

it('refuses stored contents that do not belong to the stated invoice', function () {
    $stored = (new UblGenerator)->generate(InvoiceFactory::standardInvoice())->contents;

    expect(fn () => GeneratedDocument::fromStored(Format::Ubl, $stored, 'INV-OTHER'))
        ->toThrow(EInvoicingException::class, 'INV-2024-0001');
});

it('refuses stored contents of another syntax than the stated format', function () {
    $cii = (new CiiGenerator)->generate(InvoiceFactory::standardInvoice())->contents;
    $ubl = (new UblGenerator)->generate(InvoiceFactory::standardInvoice())->contents;

    expect(fn () => GeneratedDocument::fromStored(Format::Ubl, $cii, 'INV-2024-0001'))
        ->toThrow(EInvoicingException::class, 'not a ubl document')
        ->and(fn () => GeneratedDocument::fromStored(Format::Cii, $ubl, 'INV-2024-0001'))
        ->toThrow(EInvoicingException::class, 'not a cii document');
});

it('refuses a UBL document that is not an invoice or a credit note', function () {
    $order = str_replace(
        UblGenerator::INVOICE_NS,
        'urn:oasis:names:specification:ubl:schema:xsd:Order-2',
        (new UblGenerator)->generate(InvoiceFactory::standardInvoice())->contents,
    );

    expect(fn () => GeneratedDocument::fromStored(Format::Ubl, $order, 'INV-2024-0001'))
        ->toThrow(EInvoicingException::class, 'not a ubl document');
});

it('refuses stored contents that are not XML', function (string $contents) {
    expect(fn () => GeneratedDocument::fromStored(Format::Ubl, $contents, 'INV-2024-0001'))
        ->toThrow(EInvoicingException::class, 'not a ubl document');
})->with(['', 'not xml', '<Invoice>']);

it('refuses to rebuild a format the package cannot generate', function () {
    expect(fn () => GeneratedDocument::fromStored(Format::FacturX, '%PDF-1.7', 'INV-2024-0001'))
        ->toThrow(NotImplemented::class);
});
