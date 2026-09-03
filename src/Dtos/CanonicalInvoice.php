<?php

declare(strict_types=1);

namespace Vimatech\EInvoicing\Dtos;

use DateTimeImmutable;

/**
 * The neutral, format- and network-agnostic invoice model.
 *
 * Everything in this package is produced from, or routed by, a
 * CanonicalInvoice. It deliberately knows nothing about UBL, CII, Peppol or
 * any PDP — those concerns live entirely inside the generators and drivers.
 */
final readonly class CanonicalInvoice
{
    public const TYPE_INVOICE = 380;

    public const TYPE_CREDIT_NOTE = 381;

    /**
     * @param  string  $number  Invoice number (BT-1).
     * @param  DateTimeImmutable  $issueDate  Issue date (BT-2).
     * @param  string  $currency  ISO 4217 document currency code (BT-5).
     * @param  Party  $seller  Selling party (BG-4).
     * @param  Party  $buyer  Buying party (BG-7).
     * @param  list<LineItem>  $lines  Invoice lines (BG-25), at least one.
     * @param  list<TaxBreakdown>  $taxBreakdowns  VAT subtotals (BG-23), at least one.
     * @param  int  $typeCode  Document type code (BT-3); 380 invoice, 381 credit note.
     * @param  DateTimeImmutable|null  $dueDate  Payment due date (BT-9).
     * @param  string|null  $buyerReference  Buyer reference (BT-10).
     * @param  string|null  $orderReference  Purchase order reference (BT-13).
     * @param  string|null  $note  Free-text note (BT-22).
     * @param  string|null  $paymentMeansCode  UN/ECE 4461 payment means code (BT-81).
     * @param  string|null  $payeeIban  Payment account IBAN (BT-84).
     * @param  string|null  $payeeBic  Payment service provider BIC (BT-86).
     * @param  string|null  $paymentReference  Remittance information (BT-83).
     * @param  float  $prepaidAmount  Sum already paid (BT-113).
     * @param  array<string, mixed>  $metadata  Free-form data for routing/tenancy; never serialised into documents.
     * @param  PrecedingInvoiceReference|null  $precedingInvoiceReference  The invoice this document corrects or completes (BG-3).
     */
    public function __construct(
        public string $number,
        public DateTimeImmutable $issueDate,
        public string $currency,
        public Party $seller,
        public Party $buyer,
        public array $lines,
        public array $taxBreakdowns,
        public int $typeCode = self::TYPE_INVOICE,
        public ?DateTimeImmutable $dueDate = null,
        public ?string $buyerReference = null,
        public ?string $orderReference = null,
        public ?string $note = null,
        public ?string $paymentMeansCode = null,
        public ?string $payeeIban = null,
        public ?string $payeeBic = null,
        public ?string $paymentReference = null,
        public float $prepaidAmount = 0.0,
        public array $metadata = [],
        public ?PrecedingInvoiceReference $precedingInvoiceReference = null,
    ) {}

    public function isCreditNote(): bool
    {
        return $this->typeCode === self::TYPE_CREDIT_NOTE;
    }

    /**
     * Destination country used for network routing (the buyer's country).
     */
    public function destinationCountry(): string
    {
        return $this->buyer->countryCode;
    }

    /**
     * Sum of the line net amounts (BT-106).
     */
    public function lineExtensionAmount(): float
    {
        return $this->round(array_sum(array_map(
            static fn (LineItem $line): float => $line->lineExtensionAmount,
            $this->lines,
        )));
    }

    /**
     * Total amount without VAT (BT-109).
     */
    public function taxExclusiveAmount(): float
    {
        return $this->lineExtensionAmount();
    }

    /**
     * Total VAT amount (BT-110).
     */
    public function taxAmount(): float
    {
        return $this->round(array_sum(array_map(
            static fn (TaxBreakdown $tax): float => $tax->taxAmount,
            $this->taxBreakdowns,
        )));
    }

    /**
     * Total amount with VAT (BT-112).
     */
    public function taxInclusiveAmount(): float
    {
        return $this->round($this->taxExclusiveAmount() + $this->taxAmount());
    }

    /**
     * Amount due for payment (BT-115).
     */
    public function payableAmount(): float
    {
        return $this->round($this->taxInclusiveAmount() - $this->round($this->prepaidAmount));
    }

    private function round(float $value): float
    {
        return round($value, 2);
    }
}
