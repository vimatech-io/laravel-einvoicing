<?php

declare(strict_types=1);

namespace Vimatech\EInvoicing\Dtos;

use DateTimeImmutable;

/**
 * A reference to an invoice this document corrects or completes (EN 16931
 * group BG-3).
 */
final readonly class PrecedingInvoiceReference
{
    /**
     * @param  string  $number  Number of the referenced invoice (BT-25).
     * @param  DateTimeImmutable|null  $issueDate  Issue date of the referenced invoice (BT-26); required by EN 16931 only when the number alone is not unique.
     */
    public function __construct(
        public string $number,
        public ?DateTimeImmutable $issueDate = null,
    ) {}
}
