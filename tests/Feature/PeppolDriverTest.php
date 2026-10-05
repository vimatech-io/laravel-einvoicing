<?php

declare(strict_types=1);

use Illuminate\Http\Client\Factory as HttpFactory;
use Illuminate\Http\Client\Request;
use Vimatech\EInvoicing\Enums\Format;
use Vimatech\EInvoicing\Enums\LifecycleStatus;
use Vimatech\EInvoicing\Exceptions\InvalidDriverConfig;
use Vimatech\EInvoicing\Exceptions\NetworkException;
use Vimatech\EInvoicing\Formats\CiiGenerator;
use Vimatech\EInvoicing\Formats\UblGenerator;
use Vimatech\EInvoicing\Networks\PeppolDriver;
use Vimatech\EInvoicing\Tests\Support\InvoiceFactory;
use Vimatech\EInvoicing\Tests\Support\RecordingExceptionHandler;

function peppolDriver(HttpFactory $http, array $extra = []): PeppolDriver
{
    return new PeppolDriver($http, array_merge([
        'base_url' => 'https://ap.example.test/api',
        'token' => 'secret-token',
    ], $extra), 'peppol');
}

it('posts the document and maps the partner status', function () {
    $http = new HttpFactory;
    $http->fake([
        '*/documents' => $http->response(['id' => 'msg-123', 'status' => 'delivered'], 200),
    ]);

    $invoice = InvoiceFactory::standardInvoice();
    $document = (new UblGenerator)->generate($invoice);

    $result = peppolDriver($http)->send($document, $invoice);

    expect($result->status)->toBe(LifecycleStatus::Delivered)
        ->and($result->messageId)->toBe('msg-123')
        ->and($result->network)->toBe('peppol');

    $http->assertSent(function (Request $request) use ($document) {
        $body = $request->data();

        return $request->url() === 'https://ap.example.test/api/documents'
            && $request->hasHeader('Authorization', 'Bearer secret-token')
            && $body['format'] === 'ubl'
            && $body['receiver']['id'] === '9876543210'
            && $body['document'] === $document->toBase64();
    });
});

it('defaults to Submitted when the partner returns no status', function () {
    $http = new HttpFactory;
    $http->fake(['*/documents' => $http->response(['id' => 'm1'], 200)]);

    $invoice = InvoiceFactory::standardInvoice();
    $result = peppolDriver($http)->send((new UblGenerator)->generate($invoice), $invoice);

    expect($result->status)->toBe(LifecycleStatus::Submitted);
});

it('honours a per-driver status map override', function () {
    $http = new HttpFactory;
    $http->fake(['*/documents/*/status' => $http->response(['status' => 'busy'], 200)]);

    $result = peppolDriver($http, ['status_map' => ['busy' => 'in_transit']])
        ->fetchStatus('msg-123');

    expect($result->status)->toBe(LifecycleStatus::InTransit);
});

it('decodes inbound documents from the inbox', function () {
    $http = new HttpFactory;
    $http->fake([
        '*/inbound' => $http->response([
            'documents' => [[
                'id' => 'in-1',
                'format' => 'ubl',
                'document' => base64_encode('<Invoice>hello</Invoice>'),
                'sender' => '0208:111',
            ]],
        ], 200),
    ]);

    $documents = peppolDriver($http)->receive();

    expect($documents)->toHaveCount(1)
        ->and($documents[0]->messageId)->toBe('in-1')
        ->and($documents[0]->contents)->toBe('<Invoice>hello</Invoice>')
        ->and($documents[0]->senderId)->toBe('0208:111');
});

it('wraps transport failures in a NetworkException', function () {
    $http = new HttpFactory;
    $http->fake(['*/documents' => $http->response(['error' => 'boom'], 500)]);

    $invoice = InvoiceFactory::standardInvoice();

    expect(fn () => peppolDriver($http)->send((new UblGenerator)->generate($invoice), $invoice))
        ->toThrow(NetworkException::class);
});

it('fails fast when no base_url is configured', function () {
    $invoice = InvoiceFactory::standardInvoice();

    expect(fn () => (new PeppolDriver(new HttpFactory, [], 'peppol'))
        ->send((new UblGenerator)->generate($invoice), $invoice))
        ->toThrow(InvalidDriverConfig::class, 'base_url');
});

it('refuses an inbound document whose body is not valid base64', function () {
    $http = new HttpFactory;
    $http->fake([
        '*/inbound' => $http->response([
            'documents' => [['id' => 'in-2', 'document' => '<Invoice>corrupt</Invoice>']],
        ], 200),
    ]);

    expect(fn () => peppolDriver($http)->receive())
        ->toThrow(NetworkException::class, 'in-2');
});

it('decodes an inbound body that base64-decodes to a falsy string', function () {
    $http = new HttpFactory;
    $http->fake([
        '*/inbound' => $http->response([
            'documents' => [['id' => 'in-3', 'format' => 'ubl', 'document' => base64_encode('0')]],
        ], 200),
    ]);

    expect(peppolDriver($http)->receive()[0]->contents)->toBe('0');
});

it('labels an undeclared CII document as CII rather than UBL', function () {
    $cii = (new CiiGenerator)->generate(InvoiceFactory::standardInvoice())->contents;
    $http = new HttpFactory;
    $http->fake([
        '*/inbound' => $http->response([
            'documents' => [['id' => 'in-cii', 'document' => base64_encode($cii)]],
        ], 200),
    ]);

    expect(peppolDriver($http)->receive()[0]->format)->toBe(Format::Cii);
});

it('reports an unrecognisable inbound document and still returns the others', function () {
    $ubl = (new UblGenerator)->generate(InvoiceFactory::standardInvoice())->contents;
    $exceptions = new RecordingExceptionHandler;
    $http = new HttpFactory;
    $http->fake([
        '*/inbound' => $http->response([
            'documents' => [
                ['id' => 'in-unknown', 'document' => base64_encode('not an invoice')],
                ['id' => 'in-ubl', 'document' => base64_encode($ubl)],
            ],
        ], 200),
    ]);

    $documents = (new PeppolDriver($http, ['base_url' => 'https://ap.example.test', 'token' => 't'], 'peppol', $exceptions))->receive();

    expect($documents)->toHaveCount(1)
        ->and($documents[0]->messageId)->toBe('in-ubl')
        ->and($exceptions->reported[0]->messageId)->toBe('in-unknown');
});
