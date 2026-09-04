<?php

declare(strict_types=1);

namespace Vimatech\EInvoicing\Enums;

/**
 * Rule sets a CanonicalInvoice can be validated against.
 *
 * EN 16931 is the semantic core. A CIUS narrows it by adding rules of its own;
 * those rules are not part of the core and must not be applied to a document
 * that is not being emitted under that CIUS.
 */
enum ValidationProfile: string
{
    /** The EN 16931 semantic core, as emitted by CiiGenerator. */
    case En16931 = 'en16931';

    /** EN 16931 plus the Peppol BIS Billing 3.0 rules, as emitted by UblGenerator. */
    case PeppolBis = 'peppol_bis';
}
