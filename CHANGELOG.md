# Changelog

All notable changes to `vimatech/laravel-einvoicing` will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.0.0/), and this project
adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

### Fixed

- `FrPdpDriver::capabilities()` listed `Format::FacturX`, which the package cannot generate: an
  application choosing its format with `supports(Format::FacturX)` was told yes, then
  `generate()` threw `NotImplemented`. The driver now declares `Format::Ubl` and `Format::Cii` only.
- An inbound document whose `format` field was absent or not a value of `Format` was labelled with
  a default: `cii` by `FrPdpDriver::receive()`, `ubl` by `PeppolDriver::receive()`. A Factur-X PDF
  delivered without a format reached the application as a CII invoice, and a CII document on a
  Peppol inbox as a UBL one. Both drivers now read the decoded contents instead: a PDF (`%PDF-`) is
  `Format::FacturX`; an XML document whose root is `CrossIndustryInvoice` in the CII namespace is
  `Format::Cii`; a root `Invoice` or `CreditNote` in its UBL namespace is `Format::Ubl`. A root
  with the right name but no namespace, or another namespace, is not taken for either. A format the
  partner declares and the package knows is still used as declared.
- A document whose format can be neither read from the declaration nor recognised in its contents
  is no longer returned. It is reported to the application's exception handler as
  `UnrecognisedInboundDocument`, and the other documents of the batch are returned as usual, so one
  unreadable document cannot keep the rest of an inbox from being read. This applies to every
  driver `NetworkManager` builds itself (`peppol`, `fr_pdp`, or an `AbstractHttpDriver` subclass
  referenced by class). A driver built without an exception handler, by hand or in an `extend()`
  factory, throws the exception instead, which stops the batch.

### Added

- `Vimatech\EInvoicing\Exceptions\UnrecognisedInboundDocument`, extending `EInvoicingException`,
  carrying the `network`, the `messageId`, the `declaredFormat` (or `null`) and the provider's `raw`
  entry, including the still-encoded body. Its `context()` (network, message id, declared format) is
  what Laravel adds to the log entry. Register a `reportable()` callback for it to keep or alert on
  the document.
- `AbstractHttpDriver` accepts an optional `ExceptionHandler` as a fourth constructor argument,
  which `NetworkManager` passes to the built-in drivers and to any `AbstractHttpDriver` subclass it
  instantiates, and exposes `inboundDocuments()` and `inboundFormat()` to subclasses that read an
  inbox.

### Upgrading from 2.3.0

1. Code that selected Factur-X on the `fr_pdp` network because `supports()` said so was failing at
   generation; it now gets `false` and should fall back to `Format::Cii` or `Format::Ubl`.
2. Inbound documents without a declared format may now carry a different `format` than before.
   Anything that branches on `InboundDocument::$format` should handle `Format::FacturX`.
3. Unrecognisable inbound documents appear in your exception reports instead of in the batch.
4. A subclass of `AbstractHttpDriver` whose constructor takes a fourth parameter of another type
   now receives the `ExceptionHandler` there when `NetworkManager` builds it. Accept it as the fourth
   argument, or register the driver with `extend()`.

## [2.3.0] - 2026-09-26

### Fixed

- The item net price (BT-146) was rendered through the amount formatter and rounded to two decimals:
  a line of 1000 items at 0.125 carried a price of `0.13` next to a line net amount (BT-131) of
  `125.00`. EN 16931 does not limit the decimals of a price, only of amounts. `cbc:PriceAmount` and
  `ram:ChargeAmount` now carry the price at its own precision: at least two decimals, trailing zeros
  trimmed beyond that, up to six. A price with a seventh decimal is refused.
- VAT rates (BT-119, BT-152) were rounded to two decimals: 9.975 % was emitted as `9.98`, so the VAT
  amount (BT-117) no longer matched the rate the document displayed. Rates are now rendered with up
  to four decimals, at least two, trailing zeros trimmed. A rate with a fifth decimal is refused
  rather than rounded.
- Amounts were rounded to two decimals without notice: an amount of 1.235 was emitted as `1.24`,
  so the document stated a value the issuer never invoiced. An amount that two decimals cannot
  represent (EN 16931 `BR-DEC`) is now refused with an `InvalidInvoice` naming the business term
  and, for a line or a VAT breakdown, which one. Binary float noise is not mistaken for a third
  decimal: `0.1 + 0.2` still renders as `0.30`.

### Added

- `CanonicalInvoice::$amountDecimals`, an optional last constructor argument defaulting to `2`: the
  ISO 4217 minor unit of the document currency (BT-5) as the issuer fixed it, from 0 to 4. The
  derived totals (BT-106, BT-110, BT-112, BT-115) are rounded at this scale, and the arithmetic
  checks of `InvoiceValidator` (BT-131, `BR-CO-17`, `BR-CO-10`) round at it and allow two of its
  smallest units as tolerance, which is the former fixed 0.02 at two decimals. It does not relax the
  XML rule above: a JPY document renders `1000.00`, a TND document with a real third decimal is
  refused.
- `EInvoiceManager::transmit()` (and `EInvoice::transmit()`) transmits a `GeneratedDocument` already
  rendered, routed with its `CanonicalInvoice`, firing the same events as `send()`. It refuses a
  document whose invoice number differs from the invoice's. `send()` is now `generate()` followed by
  `transmit()`, with unchanged behaviour.
- `GeneratedDocument::fromStored()` rebuilds a document from stored contents, a `Format` and the
  invoice number, with exactly the MIME type, profile and file name `generate()` sets. It refuses
  contents that are not a UBL invoice or credit note, or a CII invoice, carrying that invoice number,
  and throws `NotImplemented` for `Format::FacturX`.
- `Format::profile()` and `Format::filename()`, now the single source of both values for the
  generators and `fromStored()`.
- `Decimal::unitPrice()`, and an optional business-term label on `Decimal::amount()` and
  `Decimal::percent()` used in their error messages.
- The namespace constants `UblGenerator::CBC`, `UblGenerator::INVOICE_NS`,
  `UblGenerator::CREDIT_NOTE_NS`, `CiiGenerator::RSM` and `CiiGenerator::RAM` are public.

### Upgrading from 2.2.x

1. Documents whose amounts carry at most two decimals, rates at most two and prices at most two
   generate byte for byte as before.
2. Generation now throws `InvalidInvoice` for an amount with a third decimal, a rate with a fifth or
   a price with a seventh, where it used to round. Round such values in your application, at the
   point where the rounding is a business decision, before building the invoice.
3. To transmit the file you stored at issuance rather than a document rendered again, replace
   `EInvoice::send($invoice)` with
   `EInvoice::transmit(GeneratedDocument::fromStored($format, $contents, $invoice->number), $invoice)`.
4. For a currency whose minor unit is not 2, pass `amountDecimals` so the totals and the checks run
   at its scale.

## [2.2.0] - 2026-09-04

### Fixed

- The validator refused every invoice that carried neither a buyer reference (BT-10) nor a purchase
  order reference (BT-13), reporting it as `BR-AB`. **No rule with that identifier exists.** In the
  EN 16931 semantic model both terms are `0..1`, and none of the core rules published in the CEN
  validation artefacts references either one. The requirement is `PEPPOL-EN16931-R003` ("A buyer
  reference or purchase order reference MUST be provided"), which belongs to Peppol BIS Billing 3.0,
  not to the standard it profiles.

  Enforcing it on every document rejected invoices that EN 16931 accepts, including the ordinary
  business-to-business invoice of a seller who has no purchase order to quote. The practical
  consequence was worse than the rejection: the only way past it was to put an invented reference on
  a fiscal document. CII output was affected too, although it carries the plain EN 16931 guideline
  identifier and no Peppol rule governs it.

### Added

- `ValidationProfile` enum selecting the rule set a `CanonicalInvoice` is validated against:
  `En16931` for the semantic core, `PeppolBis` for the core plus the Peppol BIS Billing 3.0
  additions. `UblGenerator` selects `PeppolBis`, `CiiGenerator` selects `En16931`.
- `InvoiceValidator::assertConformsTo()` and `InvoiceValidator::violations()`, both taking a
  `ValidationProfile` and defaulting to `En16931`.

### Deprecated

- `InvoiceValidator::assert()` and `InvoiceValidator::collect()`, and their
  `$requireElectronicAddress` argument. That argument only ever meant "validate for Peppol", so
  `true` now selects `ValidationProfile::PeppolBis` and behaviour is unchanged for every existing
  caller. Both are removed in 3.0.0; pass a `ValidationProfile` instead.

### Upgrading from 2.1.x

1. Nothing is required. Every document that generated before still generates, byte for byte.
2. Invoices previously refused for a missing buyer or purchase order reference now generate as CII
   and as plain EN 16931. If you relied on that refusal (because you transmit through Peppol, or to
   a CIUS that carries the requirement, such as XRechnung via `BR-DE-15`), validate explicitly with
   `InvoiceValidator::assertConformsTo($invoice, ValidationProfile::PeppolBis)`.
3. The requirement remains enforced, unchanged, for every UBL document: `UblGenerator` emits Peppol
   BIS Billing 3.0 and validates against it.

## [2.1.0] - 2026-09-03

### Added

- `PrecedingInvoiceReference` DTO and the optional `CanonicalInvoice::$precedingInvoiceReference`
  constructor argument, carrying EN 16931 group **BG-3**: the number of the referenced invoice
  (BT-25) and, optionally, its issue date (BT-26). The canonical model previously had no way to
  express which invoice a credit note corrects, so the information could not reach a document at
  all, and a credit note that names no preceding invoice is rejected by the profiles that require
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
producing credit notes without it. It is therefore not enforced here, and remains a candidate for
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
  raw, undecoded string, presented as a valid invoice body: a corrupt fiscal document indexed and
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
  `Submitted` and `InTransit`, announcing the delivery of an invoice that was merely queued. It is
  now dispatched only for `Delivered` and `Accepted`.
- `EInvoiceRejected` was dispatched for `Unknown`, reporting a status absent from the driver's
  `status_map` as a refusal by the recipient. It is now dispatched only for `Rejected` and
  `Failed`; a status that is neither an arrival nor a refusal dispatches `EInvoiceDispatched`
  alone.
- `AbstractHttpDriver::exchange()` converted every exception into a transport failure, including a
  configuration error raised while building the request, presenting a permanent misconfiguration
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
- `ext-xmlwriter` and `ext-libxml` from `require`. `ext-xmlwriter` is not used anywhere in the package. `ext-libxml` is used only by `GeneratedDocument` (`libxml_use_internal_errors()` and `LIBXML_NONET`) and comes with `ext-dom`, which stays required.

### Upgrading from 1.x

1. Every HTTP network must have a `token`, or declare `'auth' => 'none'`. A network with neither
   now throws on its first request instead of transmitting unauthenticated.
2. Check the values your `networks` config actually passes. Settings that were being ignored are
   now applied: a `timeout` or `verify` you set but never took effect will start taking effect,
   and a value that cannot be read now throws at request time instead of falling back.
3. Remove any `(int)` or `(bool)` cast around `env()` in your network config. The cast turns an
   unparseable value into `0` or `false`; passing the raw value lets the package report it.
4. `receive()` can now throw `NetworkException`. If your partner returns unencoded document bodies,
   extend the driver and override `decodeInbound()`.
5. If you treat `EInvoiceDelivered` as "the invoice reached the recipient", that is now what it
   means; you will stop receiving it for submissions still in flight. If you relied on
   `EInvoiceRejected` firing for `Unknown`, poll `fetchStatus()` instead, and complete your
   `status_map` so the partner's vocabulary is actually covered.
6. `GeneratedDocument::save()` throws instead of returning `0`; drop any `if ($bytes === 0)` check.

## [1.0.0] - 2026-06-26

### Added

- Initial release.
- `CanonicalInvoice`, `Party`, `LineItem`, `TaxBreakdown`, `GeneratedDocument`, `DispatchResult`,
  `NetworkCapabilities` and `InboundDocument` readonly DTOs: the neutral, format/network-agnostic
  domain model.
- `Format` (`Ubl`, `Cii`, `FacturX`) and `LifecycleStatus` enums.
- Native `UblGenerator` producing Peppol BIS Billing 3.0 invoices and credit notes with
  `DOMDocument`, with no third-party libraries.
- Native `CiiGenerator` producing EN 16931 Cross Industry Invoice.
- `FacturXGenerator` placeholder that throws `NotImplemented` until the isolated PDF/A-3 module ships.
- Native EN 16931 mandatory-field and arithmetic validation (`InvoiceValidator`) throwing
  `InvalidInvoice` with the full list of violations.
- `EInvoiceNetwork` contract with `PeppolDriver`, `FrPdpDriver`, `NullDriver` and a documented,
  dependency-free `FakeDriver` for consumer test suites.
- Config-driven `NetworkManager` (built-in driver aliases, custom class resolution, `extend()`,
  `fake()`).
- `EInvoiceRouter`: per-country resolution with explicit fallback (`UnsupportedCountry`) and a
  per-tenant override hook.
- `EInvoice` facade and `EInvoiceManager` orchestrator.
- Lifecycle events: `EInvoiceGenerated`, `EInvoiceDispatched`, `EInvoiceDelivered`,
  `EInvoiceRejected`, `EInvoiceReceived`.
- Publishable `config/einvoicing.php` with drivers map, country routing and default profile.
- Test suite (Pest + orchestra/testbench) including UBL golden-file assertions, validation, routing
  and fallback, FakeDriver send/status/receive, events and a `Http::fake`-driven Peppol driver test.
- CI tooling: Pint (PSR-12 + strict types), PHPStan/Larastan level max.

[Unreleased]: https://github.com/vimatech-io/laravel-einvoicing/compare/v2.3.0...HEAD
[2.3.0]: https://github.com/vimatech-io/laravel-einvoicing/compare/v2.2.0...v2.3.0
[2.2.0]: https://github.com/vimatech-io/laravel-einvoicing/compare/v2.1.0...v2.2.0
[2.1.0]: https://github.com/vimatech-io/laravel-einvoicing/compare/v2.0.0...v2.1.0
[2.0.0]: https://github.com/vimatech-io/laravel-einvoicing/compare/v1.0.0...v2.0.0
[1.0.0]: https://github.com/vimatech-io/laravel-einvoicing/releases/tag/v1.0.0
