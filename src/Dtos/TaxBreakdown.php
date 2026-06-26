<?php

declare(strict_types=1);

namespace Vimatech\EInvoicing\Dtos;

/**
 * A VAT breakdown subtotal grouped by category + rate (EN 16931 group BG-23).
 */
final readonly class TaxBreakdown
{
    /**
     * @param  string  $category  VAT category code (BT-118), e.g. "S", "Z", "E", "AE".
     * @param  float  $percent  VAT rate for the category (BT-119).
     * @param  float  $taxableAmount  Sum of line net amounts in this category (BT-116).
     * @param  float  $taxAmount  VAT amount for this category (BT-117).
     * @param  string|null  $exemptionReason  Free-text exemption reason (BT-120).
     * @param  string|null  $exemptionReasonCode  Coded exemption reason (BT-121).
     */
    public function __construct(
        public string $category,
        public float $percent,
        public float $taxableAmount,
        public float $taxAmount,
        public ?string $exemptionReason = null,
        public ?string $exemptionReasonCode = null,
    ) {}
}
