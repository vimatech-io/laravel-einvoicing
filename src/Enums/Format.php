<?php

declare(strict_types=1);

namespace Vimatech\EInvoicing\Enums;

use Vimatech\EInvoicing\Exceptions\NotImplemented;
use Vimatech\EInvoicing\Formats\CiiGenerator;
use Vimatech\EInvoicing\Formats\UblGenerator;

/**
 * Structured e-invoice syntaxes this package can emit.
 */
enum Format: string
{
    /** OASIS UBL 2.1, Peppol BIS Billing 3.0 profile. */
    case Ubl = 'ubl';

    /** UN/CEFACT Cross Industry Invoice, EN 16931 syntax. */
    case Cii = 'cii';

    /** Factur-X / ZUGFeRD: hybrid PDF/A-3 + CII (deferred). */
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

    /**
     * @throws NotImplemented for a format the package cannot generate yet
     */
    public function profile(): string
    {
        return match ($this) {
            self::Ubl => UblGenerator::CUSTOMIZATION_ID,
            self::Cii => CiiGenerator::GUIDELINE_ID,
            self::FacturX => throw NotImplemented::format($this->value),
        };
    }

    /**
     * @throws NotImplemented for a format the package cannot generate yet
     */
    public function filename(string $invoiceNumber): string
    {
        $safe = preg_replace('/[^A-Za-z0-9._-]/', '-', $invoiceNumber) ?? 'invoice';

        return match ($this) {
            self::Ubl => $safe.'.xml',
            self::Cii => $safe.'-cii.xml',
            self::FacturX => throw NotImplemented::format($this->value),
        };
    }
}
