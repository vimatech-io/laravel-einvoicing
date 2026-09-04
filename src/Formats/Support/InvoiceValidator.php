<?php

declare(strict_types=1);

namespace Vimatech\EInvoicing\Formats\Support;

use Vimatech\EInvoicing\Dtos\CanonicalInvoice;
use Vimatech\EInvoicing\Dtos\LineItem;
use Vimatech\EInvoicing\Dtos\Party;
use Vimatech\EInvoicing\Dtos\TaxBreakdown;
use Vimatech\EInvoicing\Enums\ValidationProfile;
use Vimatech\EInvoicing\Exceptions\InvalidInvoice;

/**
 * Native mandatory-field and arithmetic validation for the EN 16931 core, plus
 * the additional rules of the selected profile.
 *
 * This implements the subset of the rules the package emits; it is not a full
 * Schematron engine. It exists to fail fast with actionable messages before a
 * document is ever rendered or transmitted.
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
    public static function assertConformsTo(
        CanonicalInvoice $invoice,
        ValidationProfile $profile = ValidationProfile::En16931,
    ): void {
        $violations = (new self)->violations($invoice, $profile);

        if ($violations !== []) {
            throw InvalidInvoice::withViolations($violations);
        }
    }

    /**
     * @deprecated 2.2.0 Use assertConformsTo() with a ValidationProfile. Removed in 3.0.0.
     *
     * @throws InvalidInvoice
     */
    public static function assert(CanonicalInvoice $invoice, bool $requireElectronicAddress = false): void
    {
        self::assertConformsTo($invoice, self::profileFor($requireElectronicAddress));
    }

    /**
     * @deprecated 2.2.0 Use violations() with a ValidationProfile. Removed in 3.0.0.
     *
     * @return list<string>
     */
    public function collect(CanonicalInvoice $invoice, bool $requireElectronicAddress = false): array
    {
        return $this->violations($invoice, self::profileFor($requireElectronicAddress));
    }

    private static function profileFor(bool $requireElectronicAddress): ValidationProfile
    {
        return $requireElectronicAddress
            ? ValidationProfile::PeppolBis
            : ValidationProfile::En16931;
    }

    /**
     * @return list<string>
     */
    public function violations(
        CanonicalInvoice $invoice,
        ValidationProfile $profile = ValidationProfile::En16931,
    ): array {
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

        if ($profile === ValidationProfile::PeppolBis
            && $invoice->buyerReference === null
            && $invoice->orderReference === null) {
            $violations[] = 'PEPPOL-EN16931-R003: a buyer reference (BT-10) or a purchase order reference (BT-13) must be provided for Peppol BIS Billing 3.0';
        }

        if ($invoice->precedingInvoiceReference !== null && trim($invoice->precedingInvoiceReference->number) === '') {
            $violations[] = 'BR-55 (BT-25): a preceding invoice reference must carry the number of the referenced invoice';
        }

        $this->validateParty($violations, 'Seller', $invoice->seller, $profile);
        $this->validateParty($violations, 'Buyer', $invoice->buyer, $profile);

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
    private function validateParty(array &$violations, string $role, Party $party, ValidationProfile $profile): void
    {
        if (trim($party->name) === '') {
            $violations[] = "{$role}: name is required";
        }

        if (! preg_match('/^[A-Z]{2}$/', $party->countryCode)) {
            $violations[] = "{$role}: a 2-letter ISO 3166-1 country code is required";
        }

        if ($profile === ValidationProfile::PeppolBis && ! $party->hasElectronicAddress()) {
            $violations[] = "{$role}: an electronic address (endpoint id + scheme) is required for Peppol BIS Billing 3.0";
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
