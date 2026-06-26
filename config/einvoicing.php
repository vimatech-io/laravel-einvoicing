<?php

declare(strict_types=1);

use Vimatech\EInvoicing\Enums\Format;

return [

    /*
    |--------------------------------------------------------------------------
    | Default format
    |--------------------------------------------------------------------------
    |
    | The syntax produced by EInvoice::generate() / EInvoice::send() when no
    | explicit format is supplied. One of: ubl, cii, facturx.
    |
    */

    'default_format' => env('EINVOICING_FORMAT', Format::Ubl->value),

    /*
    |--------------------------------------------------------------------------
    | Networks
    |--------------------------------------------------------------------------
    |
    | Each entry declares a network the application can dispatch through. The
    | "driver" key selects a built-in driver (peppol, fr_pdp, null, fake) or a
    | fully-qualified class implementing EInvoiceNetwork. Everything else is
    | passed through to the driver as configuration.
    |
    | Credentials must come from the environment; never commit secrets.
    |
    */

    'networks' => [

        'peppol' => [
            'driver' => 'peppol',
            'base_url' => env('PEPPOL_BASE_URL'),
            'token' => env('PEPPOL_API_TOKEN'),
            'timeout' => (int) env('PEPPOL_TIMEOUT', 30),
            'countries' => [],
            'headers' => [],

            // Adapt these to your access point's REST contract.
            'paths' => [
                'send' => '/documents',
                'status' => '/documents/{id}/status',
                'inbound' => '/inbound',
            ],

            // Map partner status strings onto LifecycleStatus values where the
            // built-in vocabulary is not enough, e.g. ['busy' => 'in_transit'].
            'status_map' => [],
        ],

        'fr_pdp' => [
            'driver' => 'fr_pdp',
            'base_url' => env('FR_PDP_BASE_URL'),
            'token' => env('FR_PDP_API_TOKEN'),
            'timeout' => (int) env('FR_PDP_TIMEOUT', 30),
            'countries' => ['FR'],
            'headers' => [],
            'paths' => [
                'send' => '/invoices',
                'status' => '/invoices/{id}',
                'inbound' => '/inbox',
            ],
            'status_map' => [],
        ],

        // A safe no-op network for local/staging environments.
        'sandbox' => [
            'driver' => 'null',
        ],

    ],

    /*
    |--------------------------------------------------------------------------
    | Country routing
    |--------------------------------------------------------------------------
    |
    | Maps an ISO 3166-1 alpha-2 destination country (the buyer's country) to a
    | network key above. EInvoice::route('BE') and EInvoice::send($invoice)
    | consult this table. Keys are matched case-insensitively.
    |
    */

    'routing' => [
        'FR' => 'fr_pdp',
        'BE' => 'peppol',
        'NL' => 'peppol',
        'DE' => 'peppol',
        'IT' => 'peppol',
        'NO' => 'peppol',
        'SE' => 'peppol',
        'FI' => 'peppol',
        'DK' => 'peppol',
        'IE' => 'peppol',
        'LU' => 'peppol',
        'ES' => 'peppol',
        'PL' => 'peppol',
        'AT' => 'peppol',
    ],

    /*
    |--------------------------------------------------------------------------
    | Fallback network
    |--------------------------------------------------------------------------
    |
    | Network key used when no route matches a country. Leave null to make
    | unmatched countries fail hard with UnsupportedCountry.
    |
    */

    'fallback' => env('EINVOICING_FALLBACK_NETWORK'),

];
