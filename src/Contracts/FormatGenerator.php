<?php

declare(strict_types=1);

namespace Vimatech\EInvoicing\Contracts;

use Vimatech\EInvoicing\Dtos\CanonicalInvoice;
use Vimatech\EInvoicing\Dtos\GeneratedDocument;
use Vimatech\EInvoicing\Enums\Format;
use Vimatech\EInvoicing\Exceptions\InvalidInvoice;
use Vimatech\EInvoicing\Exceptions\NotImplemented;

/**
 * Renders a CanonicalInvoice into a concrete structured syntax.
 */
interface FormatGenerator
{
    /**
     * The format produced by this generator.
     */
    public function format(): Format;

    /**
     * Validate and render the invoice.
     *
     * @throws InvalidInvoice when mandatory fields are missing or inconsistent.
     * @throws NotImplemented when the format is not yet available.
     */
    public function generate(CanonicalInvoice $invoice): GeneratedDocument;
}
