<?php

declare(strict_types=1);

use Vimatech\EInvoicing\Exceptions\UnsupportedCountry;
use Vimatech\EInvoicing\Networks\FrPdpDriver;
use Vimatech\EInvoicing\Networks\NetworkManager;
use Vimatech\EInvoicing\Networks\NullDriver;
use Vimatech\EInvoicing\Networks\PeppolDriver;
use Vimatech\EInvoicing\Routing\EInvoiceRouter;
use Vimatech\EInvoicing\Tests\Support\InvoiceFactory;

function makeRouter(array $routes, ?string $fallback = null): EInvoiceRouter
{
    $config = [
        'networks' => [
            'peppol' => ['driver' => 'peppol', 'base_url' => 'https://ap.test'],
            'fr_pdp' => ['driver' => 'fr_pdp', 'base_url' => 'https://pdp.test'],
            'sandbox' => ['driver' => 'null'],
        ],
    ];

    $manager = new NetworkManager(app(), $config);

    return new EInvoiceRouter($manager, $routes, $fallback);
}

it('routes a country to its configured network', function () {
    $router = makeRouter(['BE' => 'peppol', 'FR' => 'fr_pdp']);

    expect($router->routeFor('BE'))->toBeInstanceOf(PeppolDriver::class)
        ->and($router->routeFor('FR'))->toBeInstanceOf(FrPdpDriver::class);
});

it('matches country codes case-insensitively', function () {
    $router = makeRouter(['BE' => 'peppol']);

    expect($router->routeFor('be'))->toBeInstanceOf(PeppolDriver::class);
});

it('throws UnsupportedCountry when no route and no fallback exist', function () {
    $router = makeRouter(['BE' => 'peppol']);

    expect(fn () => $router->routeFor('US'))
        ->toThrow(UnsupportedCountry::class, 'US');
});

it('uses the fallback network when no explicit route matches', function () {
    $router = makeRouter(['BE' => 'peppol'], fallback: 'sandbox');

    expect($router->routeFor('US'))->toBeInstanceOf(NullDriver::class);
});

it('honours a per-tenant override that wins over the static map', function () {
    $router = makeRouter(['BE' => 'peppol'])
        ->overrideUsing(fn (string $country) => $country === 'BE' ? 'sandbox' : null);

    expect($router->routeFor('BE'))->toBeInstanceOf(NullDriver::class);
    expect(fn () => $router->routeFor('FR'))->toThrow(UnsupportedCountry::class);
});

it('lets an override defer to the normal chain by returning null', function () {
    $router = makeRouter(['BE' => 'peppol'])
        ->overrideUsing(fn () => null);

    expect($router->routeFor('BE'))->toBeInstanceOf(PeppolDriver::class);
});

it('routes an invoice by its buyer country', function () {
    $router = makeRouter(['BE' => 'peppol']);

    $network = $router->route(InvoiceFactory::standardInvoice());

    expect($network)->toBeInstanceOf(PeppolDriver::class);
});

it('reports whether a country is supported', function () {
    $router = makeRouter(['BE' => 'peppol']);

    expect($router->supports('BE'))->toBeTrue()
        ->and($router->supports('US'))->toBeFalse();
});
