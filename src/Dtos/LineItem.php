<?php

declare(strict_types=1);

namespace Vimatech\EInvoicing\Dtos;

/**
 * A single invoice line (EN 16931 business group BG-25).
 *
 * All monetary values are expressed in the document currency.
 */
final readonly class LineItem
{
    /**
     * @param  string  $id  Line identifier, unique within the document (BT-126).
     * @param  string  $name  Item name (BT-153).
     * @param  float  $quantity  Invoiced/credited quantity (BT-129).
     * @param  float  $netPrice  Net price of one item unit (BT-146).
     * @param  float  $lineExtensionAmount  Net total for the line, excl. VAT (BT-131).
     * @param  string  $taxCategory  VAT category code, e.g. "S", "Z", "E", "AE", "G", "O", "K" (BT-151).
     * @param  float  $taxPercent  VAT rate applied to the line (BT-152).
     * @param  string  $unitCode  UN/ECE Rec 20 unit of measure code, e.g. "C62" (BT-130).
     * @param  string|null  $description  Item description (BT-154).
     * @param  string|null  $sellerItemId  Seller's item identifier (BT-155).
     * @param  string|null  $buyerItemId  Buyer's item identifier (BT-156).
     * @param  float|null  $baseQuantity  Price base quantity (BT-149); defaults to 1.
     */
    public function __construct(
        public string $id,
        public string $name,
        public float $quantity,
        public float $netPrice,
        public float $lineExtensionAmount,
        public string $taxCategory,
        public float $taxPercent,
        public string $unitCode = 'C62',
        public ?string $description = null,
        public ?string $sellerItemId = null,
        public ?string $buyerItemId = null,
        public ?float $baseQuantity = null,
    ) {}
}
