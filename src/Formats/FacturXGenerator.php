<?php

declare(strict_types=1);

namespace Vimatech\EInvoicing\Formats;

use Vimatech\EInvoicing\Contracts\FormatGenerator;
use Vimatech\EInvoicing\Dtos\CanonicalInvoice;
use Vimatech\EInvoicing\Dtos\GeneratedDocument;
use Vimatech\EInvoicing\Enums\Format;
use Vimatech\EInvoicing\Exceptions\NotImplemented;

/**
 * Factur-X / ZUGFeRD generator.
 *
 * Factur-X is a hybrid PDF/A-3 carrying an embedded EN 16931 CII payload.
 * Producing conformant PDF/A-3 natively (without a PDF library) is a separate,
 * isolated concern that will ship as a dedicated module. The CII half is
 * already available today via {@see CiiGenerator}.
 */
final class FacturXGenerator implements FormatGenerator
{
    public function format(): Format
    {
        return Format::FacturX;
    }

    public function generate(CanonicalInvoice $invoice): GeneratedDocument
    {
        throw NotImplemented::format('facturx');
    }
}
