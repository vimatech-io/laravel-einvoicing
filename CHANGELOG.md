# Changelog

All notable changes to `vimatech/laravel-einvoicing` will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.0.0/), and this project
adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

## [2.1.0] - 2026-09-04

### Added

- `PrecedingInvoiceReference` DTO and the optional `CanonicalInvoice::$precedingInvoiceReference`
  constructor argument, carrying EN 16931 group **BG-3**: the number of the referenced invoice
  (BT-25) and, optionally, its issue date (BT-26). The canonical model previously had no way to
  express which invoice a credit note corrects, so the information could not reach a document at
  all — and a credit note that names no preceding invoice is rejected by the profiles that require
  the group, at transmission rather than at build time.
- `UblGenerator` emits it as `cac:BillingReference/cac:InvoiceDocumentReference`, sequenced between
  `cac:OrderReference` and `cac:AccountingSupplierParty`.
- `CiiGenerator` emits it as `ram:InvoiceReferencedDocument` under
  `ram:ApplicableHeaderTradeSettlement`, sequenced **after**
  `ram:SpecifiedTradeSettlementHeaderMonetarySummation` as `HeaderTradeSettlementType` requires.
- `InvoiceValidator` refuses a reference whose number is blank rather than emitting an empty
  `cbc:ID` / `ram:IssuerAssignedID`.

The argument is appended after `$metadata`, so no existing positional or named call changes. The
group is emitted only when it is set: documents built by 2.0.x code are byte-identical.

Neither generator keys the group on the document type. EN 16931 makes BG-3 conditional (0..n) and
valid on a `380` invoice, where it references the partial or pre-payment invoices a final invoice
completes; a credit note is not required by the standard itself to carry it. Requiring it for
`typeCode` `381` would refuse documents the standard accepts and would break every consumer already
producing credit notes without it — it is therefore not enforced here, and remains a candidate for
a future major.

## [2.0.0] - 2026-09-01

This release removes the paths where a misconfiguration, or a partner response the driver could
not read, produced a plausible-looking result instead of an error. Several of them changed no
signature and were therefore invisible: a configured timeout that never applied, an invoice
announced as delivered while it was still queued, an undecodable document body handed on as the
invoice itself. Every entry below under **Changed**, **Fixed** and **Removed** can alter what a
1.x application observes at runtime; the upgrade notes at the end list what to check.

### Fixed

- Inbound documents whose body could not be base64-decoded were handed to the application as the
  raw, undecoded string, presented as a valid invoice body — a corrupt fiscal document indexed and
  processed as a real one. `PeppolDriver::receive()` and `FrPdpDriver::receive()` now raise
  `NetworkException` naming the offending message id, and the batch stops rather than returning the
  readable part of it. A body that was valid base64 but decoded to a falsy string (`"0"`) was
  returned undecoded by the same expression.
- HTTP drivers sent **unauthenticated** requests when `token` was absent, empty or not a string.
  Only `base_url` was checked. On an accredited platform this means invoices rejected with no
  usable reason. A token is now required, with `'auth' => 'none'` as the explicit opt-out for
  partners that authenticate by mutual TLS or a signed header.
- `DriverConfig` silently substituted its default for any value that was not already of the exact
  PHP type it wanted. Environment variables are strings, so `PEPPOL_TIMEOUT=120` left the timeout
  at `30` and `PEPPOL_VERIFY=0` left certificate verification on. A malformed `headers` entry,
  `countries` entry or `paths` entry was dropped without a word. Whole-number and boolean strings
  are now understood; anything unreadable raises `InvalidDriverConfig`.
- `EInvoiceDelivered` was dispatched for every status not classed as a failure, including
  `Submitted` and `InTransit` — announcing the delivery of an invoice that was merely queued. It is
  now dispatched only for `Delivered` and `Accepted`.
- `EInvoiceRejected` was dispatched for `Unknown`, reporting a status absent from the driver's
  `status_map` as a refusal by the recipient. It is now dispatched only for `Rejected` and
  `Failed`; a status that is neither an arrival nor a refusal dispatches `EInvoiceDispatched`
  alone.
- `AbstractHttpDriver::exchange()` converted every exception into a transport failure, including a
  configuration error raised while building the request — presenting a permanent misconfiguration
  as a retryable network fault.
- `NetworkException::transport()` discarded the original exception. It is now chained as
  `$previous`, so the partner's response body and the original stack trace survive.
- `GeneratedDocument::save()` returned `0` instead of failing when the file could not be written.
- `DomBuilder::toXml()` cast a failed `DOMDocument::saveXML()` to an empty string, yielding an
  empty document that would then be transmitted as an invoice.
- `Decimal::amount()`, `percent()` and `quantity()` rendered `NAN` and `INF` as the literals
  `"nan"` and `"inf"`, which XSD decimal does not accept.
- `Decimal::quantity()` documented a two-fraction-digit minimum it did not apply to fractional
  values.
- README and config comments: `composer lint` does not exist (it is `composer format`); the
  `NetworkManager::extend()` example could never resolve, because `extend()` is keyed by driver
  alias and the example's config named a class; `fetchStatus($result->messageId)` passed a nullable
  value to a non-nullable parameter; and the quick-start emitted a Peppol `EndpointID` with the
  scheme duplicated into the element value instead of carried by `schemeID` alone.

### Added

- `InvalidDriverConfig`, raised for operator configuration mistakes. Separate from
  `NetworkException` so a consumer retrying a failed dispatch does not retry a fault that will
  never succeed.
- `auth` driver setting: `"token"` (default) or `"none"`.
- `AbstractHttpDriver::decodeInbound()`, a protected extension point for a partner that returns
  unencoded document bodies. Override it rather than accepting both forms, which cannot be told
  apart.

### Changed

- `DriverConfig::__construct()` now takes the network key as its first argument, so its exceptions
  can name the network at fault.
- A missing `base_url` raises `InvalidDriverConfig` instead of `NetworkException`.

### Removed

- The undocumented `payload` fallback key in `PeppolDriver`'s inbound mapping, which read an
  alternate response shape by guesswork.
- `ext-xmlwriter` and `ext-libxml` from `require`: neither is used anywhere in the package.

### Upgrading from 1.x

1. Every HTTP network must have a `token`, or declare `'auth' => 'none'`. A network with neither
   now throws on its first request instead of transmitting unauthenticated.
2. Check the values your `networks` config actually passes. Settings that were being ignored are
   now applied — a `timeout` or `verify` you set but never took effect will start taking effect —
   and a value that cannot be read now throws at request time instead of falling back.
3. Remove any `(int)` or `(bool)` cast around `env()` in your network config. The cast turns an
   unparseable value into `0` or `false`; passing the raw value lets the package report it.
4. `receive()` can now throw `NetworkException`. If your partner returns unencoded document bodies,
   extend the driver and override `decodeInbound()`.
5. If you treat `EInvoiceDelivered` as "the invoice reached the recipient", that is now what it
   means; you will stop receiving it for submissions still in flight. If you relied on
   `EInvoiceRejected` firing for `Unknown`, poll `fetchStatus()` instead — and complete your
   `status_map` so the partner's vocabulary is actually covered.
6. `GeneratedDocument::save()` throws instead of returning `0`; drop any `if ($bytes === 0)` check.

## [1.0.0] - 2026-06-26

### Added

- Initial release.
- `CanonicalInvoice`, `Party`, `LineItem`, `TaxBreakdown`, `GeneratedDocument`, `DispatchResult`,
  `NetworkCapabilities` and `InboundDocument` readonly DTOs — the neutral, format/network-agnostic
  domain model.
- `Format` (`Ubl`, `Cii`, `FacturX`) and `LifecycleStatus` enums.
- Native `UblGenerator` producing Peppol BIS Billing 3.0 invoices and credit notes with
  `DOMDocument` — no third-party libraries.
- Native `CiiGenerator` producing EN 16931 Cross Industry Invoice.
- `FacturXGenerator` placeholder that throws `NotImplemented` until the isolated PDF/A-3 module ships.
- Native EN 16931 mandatory-field and arithmetic validation (`InvoiceValidator`) throwing
  `InvalidInvoice` with the full list of violations.
- `EInvoiceNetwork` contract with `PeppolDriver`, `FrPdpDriver`, `NullDriver` and a documented,
  dependency-free `FakeDriver` for consumer test suites.
- Config-driven `NetworkManager` (built-in driver aliases, custom class resolution, `extend()`,
  `fake()`).
- `EInvoiceRouter` — per-country resolution with explicit fallback (`UnsupportedCountry`) and a
  per-tenant override hook.
- `EInvoice` facade and `EInvoiceManager` orchestrator.
- Lifecycle events: `EInvoiceGenerated`, `EInvoiceDispatched`, `EInvoiceDelivered`,
  `EInvoiceRejected`, `EInvoiceReceived`.
- Publishable `config/einvoicing.php` with drivers map, country routing and default profile.
- Test suite (Pest + orchestra/testbench) including UBL golden-file assertions, validation, routing
  and fallback, FakeDriver send/status/receive, events and a `Http::fake`-driven Peppol driver test.
- CI tooling: Pint (PSR-12 + strict types), PHPStan/Larastan level max.
