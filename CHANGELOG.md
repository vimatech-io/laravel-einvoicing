# Changelog

All notable changes to `vimatech/laravel-einvoicing` will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.0.0/), and this project
adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

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
