<?php

declare(strict_types=1);

use Illuminate\Http\Client\Factory as HttpFactory;
use Vimatech\EInvoicing\Enums\Format;
use Vimatech\EInvoicing\Exceptions\NetworkException;
use Vimatech\EInvoicing\Networks\FrPdpDriver;

function frPdpDriver(HttpFactory $http, array $extra = []): FrPdpDriver
{
    return new FrPdpDriver($http, array_merge([
        'base_url' => 'https://pdp.example.test',
        'token' => 'pdp-token',
    ], $extra), 'fr_pdp');
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
        ['id' => 'fr-4', 'content' => base64_encode('<CrossIndustryInvoice/>')],
        ['id' => 'fr-5', 'content' => '<CrossIndustryInvoice>corrupt</CrossIndustryInvoice>'],
    ]);

    expect(fn () => frPdpDriver($http)->receive())->toThrow(NetworkException::class, 'fr-5');
});
