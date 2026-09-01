<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Event;
use Vimatech\EInvoicing\Enums\LifecycleStatus;
use Vimatech\EInvoicing\Events\EInvoiceDelivered;
use Vimatech\EInvoicing\Events\EInvoiceDispatched;
use Vimatech\EInvoicing\Events\EInvoiceRejected;
use Vimatech\EInvoicing\Facades\EInvoice;
use Vimatech\EInvoicing\Tests\Support\InvoiceFactory;

function sendWithStatus(LifecycleStatus $status): void
{
    Event::fake([EInvoiceDispatched::class, EInvoiceDelivered::class, EInvoiceRejected::class]);

    EInvoice::fake('peppol')->alwaysReturn($status);
    EInvoice::send(InvoiceFactory::standardInvoice(), networkKey: 'peppol');
}

it('announces a delivery only for a status that means the invoice arrived', function (LifecycleStatus $status) {
    sendWithStatus($status);

    Event::assertDispatched(EInvoiceDispatched::class);
    Event::assertDispatched(EInvoiceDelivered::class);
    Event::assertNotDispatched(EInvoiceRejected::class);
})->with([LifecycleStatus::Delivered, LifecycleStatus::Accepted]);

it('announces a rejection only for a status that means the invoice was refused', function (LifecycleStatus $status) {
    sendWithStatus($status);

    Event::assertDispatched(EInvoiceDispatched::class);
    Event::assertDispatched(EInvoiceRejected::class);
    Event::assertNotDispatched(EInvoiceDelivered::class);
})->with([LifecycleStatus::Rejected, LifecycleStatus::Failed]);

it('announces neither outcome while the invoice is still in flight or unreported', function (LifecycleStatus $status) {
    sendWithStatus($status);

    Event::assertDispatched(EInvoiceDispatched::class);
    Event::assertNotDispatched(EInvoiceDelivered::class);
    Event::assertNotDispatched(EInvoiceRejected::class);
})->with([LifecycleStatus::Submitted, LifecycleStatus::InTransit, LifecycleStatus::Unknown]);
