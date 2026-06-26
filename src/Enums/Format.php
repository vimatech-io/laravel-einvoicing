<?php

declare(strict_types=1);

namespace Vimatech\EInvoicing\Enums;

/**
 * Structured e-invoice syntaxes this package can emit.
 */
enum Format: string
{
    /** OASIS UBL 2.1 — Peppol BIS Billing 3.0 profile. */
    case Ubl = 'ubl';

    /** UN/CEFACT Cross Industry Invoice — EN 16931 compliant. */
    case Cii = 'cii';

    /** Factur-X / ZUGFeRD — hybrid PDF/A-3 + CII (deferred). */
    case FacturX = 'facturx';

    /**
     * IANA media type produced for this format.
     */
    public function mimeType(): string
    {
        return match ($this) {
            self::Ubl, self::Cii => 'application/xml',
            self::FacturX => 'application/pdf',
        };
    }

    /**
     * Conventional file extension produced for this format.
     */
    public function extension(): string
    {
        return match ($this) {
            self::Ubl, self::Cii => 'xml',
            self::FacturX => 'pdf',
        };
    }
}
