<?php

declare(strict_types=1);

use Vimatech\EInvoicing\Dtos\InboundDocument;
use Vimatech\EInvoicing\Enums\Format;
use Vimatech\EInvoicing\Enums\LifecycleStatus;
use Vimatech\EInvoicing\Formats\UblGenerator;
use Vimatech\EInvoicing\Networks\FakeDriver;
use Vimatech\EInvoicing\Tests\Support\InvoiceFactory;

function fakeSend(FakeDriver $driver): void
{
    $invoice = InvoiceFactory::standardInvoice();
    $document = (new UblGenerator)->generate($invoice);
    $driver->send($document, $invoice);
}

it('records sent documents and returns a delivered result by default', function () {
    $driver = new FakeDriver;
    $invoice = InvoiceFactory::standardInvoice();
    $document = (new UblGenerator)->generate($invoice);

    $result = $driver->send($document, $invoice);

    expect($result->status)->toBe(LifecycleStatus::Delivered)
        ->and($result->successful())->toBeTrue()
        ->and($result->messageId)->not->toBeNull()
        ->and($driver->sentCount())->toBe(1)
        ->and($driver->lastSent()?->number)->toBe('INV-2024-0001');
});

it('can be told to always return a given status', function () {
    $driver = (new FakeDriver)->alwaysReturn(LifecycleStatus::Rejected);

    $invoice = InvoiceFactory::standardInvoice();
    $result = $driver->send((new UblGenerator)->generate($invoice), $invoice);

    expect($result->rejected())->toBeTrue();
});

it('returns the recorded status when polled', function () {
    $driver = new FakeDriver;
    $invoice = InvoiceFactory::standardInvoice();
    $result = $driver->send((new UblGenerator)->generate($invoice), $invoice);

    $polled = $driver->fetchStatus($result->messageId);

    expect($polled->status)->toBe(LifecycleStatus::Delivered);
});

it('returns Unknown when polling an unknown message', function () {
    expect((new FakeDriver)->fetchStatus('nope')->status)->toBe(LifecycleStatus::Unknown);
});

it('drains queued inbound documents on receive', function () {
    $driver = new FakeDriver;
    $driver->pushInbound(new InboundDocument('fake', 'm-1', Format::Ubl, '<Invoice/>'));

    expect($driver->receive())->toHaveCount(1)
        ->and($driver->receive())->toBeEmpty();
});

it('supports filtered queries and assertions', function () {
    $driver = new FakeDriver;
    fakeSend($driver);

    $driver->assertSent();
    $driver->assertSentCount(1);
    $driver->assertSent(fn ($invoice) => $invoice->number === 'INV-2024-0001');

    expect(fn () => $driver->assertNothingSent())->toThrow(RuntimeException::class);
    expect(fn () => $driver->assertSent(fn ($invoice) => $invoice->number === 'OTHER'))
        ->toThrow(RuntimeException::class);
});

it('passes assertNothingSent when nothing was sent', function () {
    (new FakeDriver)->assertNothingSent();
})->throwsNoExceptions();
