<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Event;
use Vimatech\EInvoicing\Dtos\InboundDocument;
use Vimatech\EInvoicing\Enums\Format;
use Vimatech\EInvoicing\Enums\LifecycleStatus;
use Vimatech\EInvoicing\Events\EInvoiceDelivered;
use Vimatech\EInvoicing\Events\EInvoiceDispatched;
use Vimatech\EInvoicing\Events\EInvoiceGenerated;
use Vimatech\EInvoicing\Events\EInvoiceReceived;
use Vimatech\EInvoicing\Events\EInvoiceRejected;
use Vimatech\EInvoicing\Facades\EInvoice;
use Vimatech\EInvoicing\Tests\Support\InvoiceFactory;

it('dispatches generated, dispatched and delivered events on a successful send', function () {
    Event::fake();
    EInvoice::fake('peppol');

    $result = EInvoice::send(InvoiceFactory::standardInvoice());

    expect($result->status)->toBe(LifecycleStatus::Delivered);

    Event::assertDispatched(EInvoiceGenerated::class);
    Event::assertDispatched(EInvoiceDispatched::class);
    Event::assertDispatched(EInvoiceDelivered::class);
    Event::assertNotDispatched(EInvoiceRejected::class);
});

it('dispatches a rejected event when the network rejects', function () {
    Event::fake();
    EInvoice::fake('peppol')->alwaysReturn(LifecycleStatus::Rejected);

    EInvoice::send(InvoiceFactory::standardInvoice());

    Event::assertDispatched(EInvoiceRejected::class);
    Event::assertNotDispatched(EInvoiceDelivered::class);
});

it('dispatches a generated event for a bare generate call', function () {
    Event::fake();

    EInvoice::generate(InvoiceFactory::standardInvoice());

    Event::assertDispatched(EInvoiceGenerated::class, function (EInvoiceGenerated $event) {
        return $event->document->invoiceNumber === 'INV-2024-0001';
    });
});

it('dispatches a received event per inbound document', function () {
    Event::fake();
    $fake = EInvoice::fake('peppol');
    $fake->pushInbound(new InboundDocument(
        network: 'peppol',
        messageId: 'in-1',
        format: Format::Ubl,
        contents: '<Invoice/>',
    ));

    $documents = EInvoice::receive('peppol');

    expect($documents)->toHaveCount(1);
    Event::assertDispatched(EInvoiceReceived::class);
});
