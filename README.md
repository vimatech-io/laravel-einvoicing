# Laravel E-Invoicing

[![CI](https://github.com/vimatech-io/laravel-einvoicing/actions/workflows/ci.yml/badge.svg)](https://github.com/vimatech-io/laravel-einvoicing/actions/workflows/ci.yml)
[![Latest Version on Packagist](https://img.shields.io/packagist/v/vimatech/laravel-einvoicing.svg)](https://packagist.org/packages/vimatech/laravel-einvoicing)
[![Total Downloads](https://img.shields.io/packagist/dt/vimatech/laravel-einvoicing.svg)](https://packagist.org/packages/vimatech/laravel-einvoicing)
[![License](https://img.shields.io/packagist/l/vimatech/laravel-einvoicing.svg)](https://packagist.org/packages/vimatech/laravel-einvoicing)

Generate compliant structured e-invoices **natively** and dispatch them through pluggable
networks (Peppol access points, French PDPs), with per-country routing — for Laravel 11, 12 and 13.

> **Zero third-party runtime dependencies.** Every document is built with PHP's own `ext-dom`;
> every network call uses Laravel's own HTTP client. No `horstoeko/zugferd`, no UBL libraries,
> no PDF libraries. This is a deliberate security & maintenance policy.

## Features

- **Native generators** — Peppol BIS Billing 3.0 (UBL invoice + credit note) and EN 16931 CII,
  emitted directly with `DOMDocument`. Factur-X (PDF/A-3) is stubbed for a later isolated module.
- **Neutral domain model** — a single `CanonicalInvoice` DTO; no format or vendor concept ever
  leaks into your application.
- **Pluggable networks** — `PeppolDriver`, `FrPdpDriver`, `NullDriver`, `FakeDriver`, plus your own.
- **Per-country routing** — map destination countries to networks, with a fallback and a
  per-tenant override hook.
- **Profile-scoped validation** — mandatory-field and arithmetic checks fail fast with actionable
  messages before anything is rendered or transmitted. The EN 16931 core applies to every document;
  the extra rules of a CIUS apply only when that profile is the one being emitted. Arithmetic checks
  allow a fixed 0.02 tolerance to absorb per-line rounding.
- **Lifecycle events** — `EInvoiceGenerated`, `EInvoiceDispatched`, `EInvoiceDelivered`,
  `EInvoiceRejected`, `EInvoiceReceived`.

## Requirements

- PHP 8.3+
- Laravel 11, 12 or 13
- Extensions: `ext-dom`, `ext-mbstring`

## Installation

```bash
composer require vimatech/laravel-einvoicing
```

Publish the config (optional):

```bash
php artisan vendor:publish --tag=einvoicing-config
```

## Quick start

### 1. Build a canonical invoice

The `CanonicalInvoice` is the only model you ever construct. It is format- and network-agnostic.

```php
use Vimatech\EInvoicing\Dtos\{CanonicalInvoice, Party, LineItem, TaxBreakdown};

$invoice = new CanonicalInvoice(
    number: 'INV-2024-0001',
    issueDate: new DateTimeImmutable('2024-01-15'),
    currency: 'EUR',
    seller: new Party(
        name: 'Acme Trading Ltd.',
        countryCode: 'BE',
        endpointId: '0123456789',        // Peppol electronic address (BT-34), bare
        endpointScheme: '0208',          // EAS scheme id, emitted as schemeID
        vatId: 'BE0123456789',
        legalRegistrationId: '0123456789',
        legalRegistrationScheme: '0208',
        street: 'Main street 1',
        city: 'Brussels',
        postalZone: '1050',
    ),
    buyer: new Party(
        name: 'Globex NV',
        countryCode: 'BE',
        endpointId: '9876543210',
        endpointScheme: '0208',
        vatId: 'BE9876543210',
        street: 'Market square 9',
        city: 'Antwerp',
        postalZone: '2000',
    ),
    lines: [
        new LineItem(
            id: '1',
            name: 'Laptop computer',
            quantity: 5.0,
            netPrice: 200.0,
            lineExtensionAmount: 1000.0,
            taxCategory: 'S',
            taxPercent: 21.0,
        ),
    ],
    taxBreakdowns: [
        new TaxBreakdown(category: 'S', percent: 21.0, taxableAmount: 1000.0, taxAmount: 210.0),
    ],
    dueDate: new DateTimeImmutable('2024-02-14'),
    buyerReference: 'PO-98765',
);
```

> Document totals (line extension, tax exclusive/inclusive, payable) are derived from the lines
> and the VAT breakdown — you do not pass them in.

### 2. Generate a UBL (Peppol BIS 3.0) document

```php
use Vimatech\EInvoicing\Facades\EInvoice;
use Vimatech\EInvoicing\Enums\Format;

$document = EInvoice::format(Format::Ubl)->generate($invoice);

$document->contents;        // the XML string
$document->mimeType;        // application/xml
$document->profile;         // urn:cen.eu:en16931:2017#compliant#urn:fdc:peppol.eu:2017:poacc:billing:3.0
$document->save('/path/to/INV-2024-0001.xml');
```

A **credit note** is the same call with `typeCode: CanonicalInvoice::TYPE_CREDIT_NOTE` — the
generator switches to the `CreditNote` root and `CreditedQuantity` automatically. Generate **CII**
with `Format::Cii`.

Name the invoice a credit note corrects with `precedingInvoiceReference` (EN 16931 group BG-3):

```php
use Vimatech\EInvoicing\Dtos\PrecedingInvoiceReference;

$creditNote = new CanonicalInvoice(
    // ...
    typeCode: CanonicalInvoice::TYPE_CREDIT_NOTE,
    precedingInvoiceReference: new PrecedingInvoiceReference(
        number: 'INV-2024-0001',                       // BT-25
        issueDate: new DateTimeImmutable('2024-01-15'), // BT-26, optional
    ),
);
```

It becomes `cac:BillingReference/cac:InvoiceDocumentReference` in UBL and
`ram:InvoiceReferencedDocument` in CII. EN 16931 leaves the group optional, so the package does not
require it — but a credit note that names no preceding invoice is rejected by the profiles that do,
including the French PDP rules. The same field is valid on a `380` invoice, where it points at the
partial or pre-payment invoices a final invoice completes.

If a mandatory field is missing or the arithmetic does not balance, generation throws
`InvalidInvoice`, which carries the full list of violations:

```php
use Vimatech\EInvoicing\Exceptions\InvalidInvoice;

try {
    EInvoice::format(Format::Ubl)->generate($invoice);
} catch (InvalidInvoice $e) {
    $e->violations(); // ['BT-1: invoice number is required', ...]
}
```

Which rules run depends on what is being emitted. `Format::Cii` carries the plain EN 16931 guideline
identifier and is validated against the core alone; `Format::Ubl` carries the Peppol BIS Billing 3.0
customization identifier and additionally enforces the Peppol rules — electronic addresses for both
parties, and `PEPPOL-EN16931-R003`, which requires a buyer reference (BT-10) or a purchase order
reference (BT-13). Both terms are optional in the core standard, so neither is required of a CII
document.

Validate against a profile without generating anything:

```php
use Vimatech\EInvoicing\Enums\ValidationProfile;
use Vimatech\EInvoicing\Formats\Support\InvoiceValidator;

InvoiceValidator::assertConformsTo($invoice, ValidationProfile::PeppolBis);

(new InvoiceValidator)->violations($invoice); // EN 16931 core, as a list
```

Reach for `ValidationProfile::PeppolBis` on a CII document when you transmit it through Peppol, or
to a CIUS that carries the same requirement — XRechnung enforces the buyer reference through
`BR-DE-15`, and Chorus Pro requires a service code or an engagement number for the public entities
whose directory entry demands one.

### 3. Send through a network

`send()` renders the document, routes it by the **buyer's country**, transmits it, and fires the
lifecycle events:

```php
$result = EInvoice::send($invoice); // Format defaults to config('einvoicing.default_format')

$result->status;       // LifecycleStatus::Delivered | Submitted | Rejected | ...
$result->messageId;    // poll later with fetchStatus()
$result->successful(); // bool
```

Force a format or a specific network:

```php
EInvoice::send($invoice, Format::Ubl, networkKey: 'peppol');
```

Resolve a network yourself:

```php
EInvoice::route('BE');        // network responsible for Belgium
EInvoice::network('peppol');  // a network by key

// messageId is null when the network returned none (NullDriver, a partner that
// does not acknowledge with an id), so guard before polling.
if ($result->messageId !== null) {
    $status = EInvoice::network('peppol')->fetchStatus($result->messageId);
}
```

`send()` always fires `EInvoiceDispatched`. `EInvoiceDelivered` fires only for `Delivered` and
`Accepted`, `EInvoiceRejected` only for `Rejected` and `Failed`. A queued submission, a document
still in transit, or a status your `status_map` does not cover fires neither — poll `fetchStatus()`
rather than treating the absence of a delivery as a refusal.

### 4. Receive inbound documents

```php
foreach (EInvoice::receive('peppol') as $inbound) {
    $inbound->contents;  // decoded document body
    $inbound->senderId;  // sender electronic address
}
// each inbound document also fires an EInvoiceReceived event
```

Bodies arrive base64-encoded. A body that is absent, that is not valid base64, or that decodes to
nothing raises `NetworkException` naming the offending message id, and the whole batch stops — the
package will not hand your application a document it could not decode. If your partner returns
unencoded bodies, extend the driver and override `decodeInbound()`; do not make it accept both,
since the two cannot be told apart and the wrong guess silently yields a corrupt invoice.

## Configuration

`config/einvoicing.php` declares the available **networks**, the **country → network** routing
table, and the default format. Credentials come from the environment.

HTTP drivers read `base_url`, `token`, `auth`, `timeout`, `headers`, `verify`, `paths` and
`status_map`. A setting that is present but cannot be read as the type it needs raises
`InvalidDriverConfig` rather than being ignored, and environment strings such as `"120"` and
`"false"` are understood — a value you set is either applied or reported, never dropped.

```php
'networks' => [
    'peppol' => [
        'driver' => 'peppol',
        'base_url' => env('PEPPOL_BASE_URL'),
        'token' => env('PEPPOL_API_TOKEN'),
        'paths' => ['send' => '/documents', 'status' => '/documents/{id}/status', 'inbound' => '/inbound'],
        'status_map' => [],   // map partner status strings -> LifecycleStatus values
    ],
    'fr_pdp' => ['driver' => 'fr_pdp', 'base_url' => env('FR_PDP_BASE_URL'), 'token' => env('FR_PDP_API_TOKEN')],
    'sandbox' => ['driver' => 'null'],
],

'routing' => [
    'FR' => 'fr_pdp',
    'BE' => 'peppol',
    'NL' => 'peppol',
    // ...
],

'fallback' => env('EINVOICING_FALLBACK_NETWORK'), // null => unmatched countries throw
```

### Adapting a built-in driver to your partner

`PeppolDriver` and `FrPdpDriver` speak a small, neutral REST shape. Point them at your access
point / PDP by overriding `paths` and, where the vocabulary differs, `status_map` — no code change:

```php
'peppol' => [
    'driver' => 'peppol',
    'base_url' => env('PEPPOL_BASE_URL'),
    'token' => env('PEPPOL_API_TOKEN'),
    'timeout' => env('PEPPOL_TIMEOUT', 30),
    'paths' => [
        'send' => '/v2/outbound',
        'status' => '/v2/messages/{id}',
        'inbound' => '/v2/inbound',
    ],
    'status_map' => [
        'in_progress' => 'in_transit',
        'done' => 'delivered',
    ],
],
```

A missing or empty `token` is refused: an unauthenticated request to an accredited platform is
rejected without a reason you can act on. When your partner authenticates another way — mutual
TLS, a signed header — declare it, and configure no token:

```php
'peppol' => [
    'driver' => 'peppol',
    'base_url' => env('PEPPOL_BASE_URL'),
    'auth' => 'none',
    'headers' => ['X-Api-Signature' => env('PEPPOL_SIGNATURE')],
],
```

## Adding a country

Add a row to the `routing` map pointing at any configured network key:

```php
'routing' => [
    'ES' => 'peppol',
    'IT' => 'peppol',
],
```

Unmatched countries throw `UnsupportedCountry` unless a `fallback` network is set.

### Per-tenant routing override

Register a resolver **in a service provider** to override routing per tenant or per invoice.
Returning a network key wins over the static map; returning `null` defers to it. The router is a
singleton, so the resolver lives for the whole process: under Octane, registering it inside a
request leaks it to every later request on that worker. Resolve the tenant inside the closure, as
below, rather than closing over one:

```php
use Vimatech\EInvoicing\Facades\EInvoice;

EInvoice::router()->overrideUsing(function (string $country, ?CanonicalInvoice $invoice) {
    return tenant()->prefersDirectPeppol() ? 'peppol' : null;
});
```

## Adding a driver

Implement `EInvoiceNetwork` (or extend `AbstractHttpDriver` for a REST partner) — keep every vendor
concept inside the driver:

```php
namespace App\EInvoicing;

use Vimatech\EInvoicing\Contracts\EInvoiceNetwork;
use Vimatech\EInvoicing\Dtos\{CanonicalInvoice, DispatchResult, GeneratedDocument, NetworkCapabilities};
use Vimatech\EInvoicing\Enums\{Format, LifecycleStatus};

final class AcmeDriver implements EInvoiceNetwork
{
    public function __construct(private array $config, private string $key) {}

    public function key(): string { return $this->key; }

    public function send(GeneratedDocument $document, CanonicalInvoice $invoice): DispatchResult
    {
        // ... call your partner, then normalise the response ...
        return new DispatchResult(LifecycleStatus::Submitted, $this->key, messageId: '...');
    }

    public function fetchStatus(string $messageId): DispatchResult { /* ... */ }
    public function receive(): array { return []; }
    public function capabilities(): NetworkCapabilities
    {
        return new NetworkCapabilities($this->key, formats: [Format::Ubl]);
    }
}
```

Reference it by class in config (it is resolved from the container):

```php
'networks' => [
    'acme' => ['driver' => App\EInvoicing\AcmeDriver::class, /* ... */],
],
```

Or register a factory for a driver alias at runtime. `extend()` is keyed by the **driver** name,
so the network entry must name that alias:

```php
'networks' => [
    'acme' => ['driver' => 'acme', 'base_url' => env('ACME_BASE_URL')],
],
```

```php
app(\Vimatech\EInvoicing\Networks\NetworkManager::class)
    ->extend('acme', fn (array $config, string $key) => new App\EInvoicing\AcmeDriver($config, $key));
```

## Testing with the FakeDriver

A dependency-free `FakeDriver` is shipped for **your** test suite. Swap any network for it and
assert against what was sent — no HTTP, no credentials:

```php
use Vimatech\EInvoicing\Facades\EInvoice;
use Vimatech\EInvoicing\Enums\LifecycleStatus;

it('sends the invoice to Peppol', function () {
    $fake = EInvoice::fake('peppol');

    EInvoice::send($invoice);

    $fake->assertSent(fn ($invoice) => $invoice->number === 'INV-2024-0001');
    $fake->assertSentCount(1);
    expect($fake->lastSent()->number)->toBe('INV-2024-0001');
});

it('handles a rejection', function () {
    EInvoice::fake('peppol')->alwaysReturn(LifecycleStatus::Rejected);

    expect(EInvoice::send($invoice)->rejected())->toBeTrue();
});
```

`FakeDriver` also supports `pushInbound()` for `receive()` flows, the inspection API
(`sent()`, `sentCount()`, `hasSent()`, `lastSent()`) and the `assert*` helpers.

## Conformance & testing notes

- **Profiles emitted**: Peppol BIS Billing 3.0 — `CustomizationID`
  `urn:cen.eu:en16931:2017#compliant#urn:fdc:peppol.eu:2017:poacc:billing:3.0`,
  `ProfileID` `urn:fdc:peppol.eu:2017:poacc:billing:01:1.0`. CII carries the EN 16931 guideline
  `urn:cen.eu:en16931:2017`.
- **Scope**: only the subset of business terms required for the supported fields is emitted, in the
  UBL 2.1 / CII sequence order. Document-level allowances/charges and multi-currency VAT accounting
  are intentionally out of scope for this release.
- **Golden files**: UBL output is asserted byte-for-byte against committed golden samples
  (`tests/fixtures/peppol/`) shaped after the official Peppol BIS 3.0 examples, plus structural
  XPath assertions for identifiers and balancing totals.
- **Recommended external validation**: before production, run generated XML through the official
  [Peppol BIS / EN 16931 validator](https://ecosio.com/en/peppol-and-xml-document-validator/) or the
  CEN Schematron. Native validation here is a fast pre-flight, not a substitute for the Schematron.

Run the suite:

```bash
composer test      # Pest + orchestra/testbench
composer analyse   # PHPStan level max
composer format    # Laravel Pint
```

## Architecture

```
CanonicalInvoice ──► FormatGenerator ──► GeneratedDocument
       │              (Ubl / Cii / FacturX)
       │
       └──► EInvoiceRouter ──► EInvoiceNetwork ──► DispatchResult
            (by country)       (Peppol / FrPdp / Null / Fake / yours)
```

- `Dtos/` — readonly value objects (the neutral model).
- `Formats/` — native `DOMDocument` generators + validation.
- `Networks/` — drivers + the config-driven `NetworkManager`.
- `Routing/` — `EInvoiceRouter` (country map + fallback + override hook).
- `Events/`, `Exceptions/`, `Facades/` — the glue.

## Contributing

Contributions are welcome.

Please ensure:
- Tests pass (`composer test`)
- PHPStan passes (`composer analyse`)
- Code style is formatted with Pint (`composer format`)

Please see [CONTRIBUTING](CONTRIBUTING.md) for details.

## Security Vulnerabilities

Please review our [Security Policy](SECURITY.md) for reporting vulnerabilities.

## License

The MIT License (MIT). Please see [License File](LICENSE) for more information.

## Credits

Built and maintained by [Vimatech](https://vimatech.io).
Created by [Adel Zemzemi](https://github.com/adelzemzemi).
