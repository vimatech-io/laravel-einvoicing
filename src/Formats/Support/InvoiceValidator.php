<?php

declare(strict_types=1);

namespace Vimatech\EInvoicing\Formats\Support;

use Vimatech\EInvoicing\Dtos\CanonicalInvoice;
use Vimatech\EInvoicing\Dtos\LineItem;
use Vimatech\EInvoicing\Dtos\Party;
use Vimatech\EInvoicing\Dtos\TaxBreakdown;
use Vimatech\EInvoicing\Exceptions\InvalidInvoice;

/**
 * Native EN 16931 mandatory-field and arithmetic validation.
 *
 * This implements the subset of the standard the package emits; it is not a
 * full Schematron engine. It exists to fail fast with actionable messages
 * before a document is ever rendered or transmitted.
 */
final class InvoiceValidator
{
    /** VAT categories that must carry a positive rate. */
    private const RATED_CATEGORIES = ['S'];

    /** VAT categories that must carry a zero rate. */
    private const UNRATED_CATEGORIES = ['Z', 'E', 'AE', 'G', 'O', 'K'];

    /** VAT categories that require an exemption reason or code. */
    private const EXEMPTION_CATEGORIES = ['E', 'AE', 'G', 'O', 'K'];

    private const TOLERANCE = 0.02;

    /**
     * @throws InvalidInvoice
     */
    public static function assert(CanonicalInvoice $invoice, bool $requireElectronicAddress = false): void
    {
        $violations = (new self)->collect($invoice, $requireElectronicAddress);

        if ($violations !== []) {
            throw InvalidInvoice::withViolations($violations);
        }
    }

    /**
     * @return list<string>
     */
    public function collect(CanonicalInvoice $invoice, bool $requireElectronicAddress = false): array
    {
        $violations = [];

        if (trim($invoice->number) === '') {
            $violations[] = 'BT-1: invoice number is required';
        }

        if (! preg_match('/^[A-Z]{3}$/', $invoice->currency)) {
            $violations[] = 'BT-5: a 3-letter ISO 4217 currency code is required';
        }

        if (! in_array($invoice->typeCode, [CanonicalInvoice::TYPE_INVOICE, CanonicalInvoice::TYPE_CREDIT_NOTE], true)) {
            $violations[] = 'BT-3: unsupported document type code '.$invoice->typeCode;
        }

        if ($invoice->buyerReference === null && $invoice->orderReference === null) {
            $violations[] = 'BR-AB: either a buyer reference (BT-10) or an order reference (BT-13) is required';
        }

        $this->validateParty($violations, 'Seller', $invoice->seller, requireElectronicAddress: $requireElectronicAddress);
        $this->validateParty($violations, 'Buyer', $invoice->buyer, requireElectronicAddress: $requireElectronicAddress);

        if ($invoice->lines === []) {
            $violations[] = 'BG-25: at least one invoice line is required';
        }

        if ($invoice->taxBreakdowns === []) {
            $violations[] = 'BG-23: at least one VAT breakdown is required';
        }

        foreach ($invoice->lines as $index => $line) {
            $this->validateLine($violations, $index, $line);
        }

        foreach ($invoice->taxBreakdowns as $index => $breakdown) {
            $this->validateBreakdown($violations, $index, $breakdown, $invoice);
        }

        $this->validateTotals($violations, $invoice);

        return $violations;
    }

    /**
     * @param  list<string>  $violations
     */
    private function validateParty(array &$violations, string $role, Party $party, bool $requireElectronicAddress): void
    {
        if (trim($party->name) === '') {
            $violations[] = "{$role}: name is required";
        }

        if (! preg_match('/^[A-Z]{2}$/', $party->countryCode)) {
            $violations[] = "{$role}: a 2-letter ISO 3166-1 country code is required";
        }

        if ($requireElectronicAddress && ! $party->hasElectronicAddress()) {
            $violations[] = "{$role}: an electronic address (endpoint id + scheme) is required for this network";
        }
    }

    /**
     * @param  list<string>  $violations
     */
    private function validateLine(array &$violations, int $index, LineItem $line): void
    {
        $position = $line->id !== '' ? $line->id : (string) ($index + 1);

        if (trim($line->id) === '') {
            $violations[] = "Line {$position} (BT-126): a line identifier is required";
        }

        if (trim($line->name) === '') {
            $violations[] = "Line {$position} (BT-153): an item name is required";
        }

        if ($line->taxCategory === '') {
            $violations[] = "Line {$position} (BT-151): a VAT category code is required";
        }

        if ($line->taxPercent < 0) {
            $violations[] = "Line {$position} (BT-152): the VAT rate cannot be negative";
        }

        $expected = round($line->quantity * $line->netPrice, 2);
        if (abs($expected - round($line->lineExtensionAmount, 2)) > self::TOLERANCE) {
            $violations[] = "Line {$position} (BT-131): net amount {$line->lineExtensionAmount} does not match quantity × price ({$expected})";
        }
    }

    /**
     * @param  list<string>  $violations
     */
    private function validateBreakdown(array &$violations, int $index, TaxBreakdown $breakdown, CanonicalInvoice $invoice): void
    {
        $category = $breakdown->category;
        $label = 'VAT breakdown #'.($index + 1)." ({$category})";

        if ($category === '') {
            $violations[] = "{$label}: a VAT category code is required";

            return;
        }

        if (in_array($category, self::RATED_CATEGORIES, true) && $breakdown->percent <= 0) {
            $violations[] = "{$label} (BR-S-05): standard-rated VAT requires a rate greater than zero";
        }

        if (in_array($category, self::UNRATED_CATEGORIES, true) && $breakdown->percent !== 0.0) {
            $violations[] = "{$label}: category {$category} requires a zero VAT rate";
        }

        if (in_array($category, self::EXEMPTION_CATEGORIES, true)
            && $breakdown->exemptionReason === null
            && $breakdown->exemptionReasonCode === null) {
            $violations[] = "{$label}: an exemption reason or reason code is required";
        }

        if (in_array($category, self::RATED_CATEGORIES, true)
            && ! $invoice->seller->hasVatId()) {
            $violations[] = 'BR-S-02: the seller VAT identifier (BT-31) is required when standard-rated VAT is applied';
        }

        $expectedTax = round($breakdown->taxableAmount * $breakdown->percent / 100, 2);
        if (abs($expectedTax - round($breakdown->taxAmount, 2)) > self::TOLERANCE) {
            $violations[] = "{$label} (BR-CO-17): VAT amount {$breakdown->taxAmount} does not match taxable {$breakdown->taxableAmount} × {$breakdown->percent}% ({$expectedTax})";
        }
    }

    /**
     * @param  list<string>  $violations
     */
    private function validateTotals(array &$violations, CanonicalInvoice $invoice): void
    {
        if ($invoice->lines === [] || $invoice->taxBreakdowns === []) {
            return;
        }

        $lineSum = $invoice->lineExtensionAmount();
        $taxableSum = round(array_sum(array_map(
            static fn (TaxBreakdown $tax): float => $tax->taxableAmount,
            $invoice->taxBreakdowns,
        )), 2);

        if (abs($lineSum - $taxableSum) > self::TOLERANCE) {
            $violations[] = "BR-CO-10: the sum of line net amounts ({$lineSum}) must equal the sum of VAT taxable amounts ({$taxableSum})";
        }
    }
}
