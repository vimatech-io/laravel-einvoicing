<?php

declare(strict_types=1);

namespace Vimatech\EInvoicing\Formats;

use DOMElement;
use Vimatech\EInvoicing\Contracts\FormatGenerator;
use Vimatech\EInvoicing\Dtos\CanonicalInvoice;
use Vimatech\EInvoicing\Dtos\GeneratedDocument;
use Vimatech\EInvoicing\Dtos\LineItem;
use Vimatech\EInvoicing\Dtos\Party;
use Vimatech\EInvoicing\Dtos\TaxBreakdown;
use Vimatech\EInvoicing\Enums\Format;
use Vimatech\EInvoicing\Enums\ValidationProfile;
use Vimatech\EInvoicing\Formats\Support\Decimal;
use Vimatech\EInvoicing\Formats\Support\DomBuilder;
use Vimatech\EInvoicing\Formats\Support\InvoiceValidator;

/**
 * Native generator for the Peppol BIS Billing 3.0 profile (OASIS UBL 2.1),
 * covering both the Invoice and Credit Note transactions.
 *
 * Built entirely with ext-dom; no third-party libraries are involved. Only the
 * subset of UBL required by the supported business terms is emitted, in the
 * exact element order the UBL 2.1 sequence demands.
 */
final class UblGenerator implements FormatGenerator
{
    private const CBC = 'urn:oasis:names:specification:ubl:schema:xsd:CommonBasicComponents-2';

    private const CAC = 'urn:oasis:names:specification:ubl:schema:xsd:CommonAggregateComponents-2';

    private const INVOICE_NS = 'urn:oasis:names:specification:ubl:schema:xsd:Invoice-2';

    private const CREDIT_NOTE_NS = 'urn:oasis:names:specification:ubl:schema:xsd:CreditNote-2';

    public const CUSTOMIZATION_ID = 'urn:cen.eu:en16931:2017#compliant#urn:fdc:peppol.eu:2017:poacc:billing:3.0';

    public const PROFILE_ID = 'urn:fdc:peppol.eu:2017:poacc:billing:01:1.0';

    private const VAT_SCHEME = 'VAT';

    public function format(): Format
    {
        return Format::Ubl;
    }

    public function generate(CanonicalInvoice $invoice): GeneratedDocument
    {
        InvoiceValidator::assertConformsTo($invoice, ValidationProfile::PeppolBis);

        $isCreditNote = $invoice->isCreditNote();

        $builder = new DomBuilder(
            $isCreditNote ? 'CreditNote' : 'Invoice',
            $isCreditNote ? self::CREDIT_NOTE_NS : self::INVOICE_NS,
            ['cac' => self::CAC, 'cbc' => self::CBC],
        );

        $root = $builder->root();

        $this->appendHeader($builder, $root, $invoice, $isCreditNote);
        $this->appendReferences($builder, $root, $invoice);
        $this->appendParty($builder, $root, 'cac:AccountingSupplierParty', $invoice->seller);
        $this->appendParty($builder, $root, 'cac:AccountingCustomerParty', $invoice->buyer);
        $this->appendPaymentMeans($builder, $root, $invoice);
        $this->appendPaymentTerms($builder, $root, $invoice);
        $this->appendTaxTotal($builder, $root, $invoice);
        $this->appendMonetaryTotal($builder, $root, $invoice);
        $this->appendLines($builder, $root, $invoice, $isCreditNote);

        return new GeneratedDocument(
            format: Format::Ubl,
            contents: $builder->toXml(),
            mimeType: Format::Ubl->mimeType(),
            filename: $this->filename($invoice),
            profile: self::CUSTOMIZATION_ID,
            invoiceNumber: $invoice->number,
        );
    }

    private function appendHeader(DomBuilder $b, DOMElement $root, CanonicalInvoice $invoice, bool $isCreditNote): void
    {
        $b->child($root, 'cbc:CustomizationID', self::CUSTOMIZATION_ID);
        $b->child($root, 'cbc:ProfileID', self::PROFILE_ID);
        $b->child($root, 'cbc:ID', $invoice->number);
        $b->child($root, 'cbc:IssueDate', $invoice->issueDate->format('Y-m-d'));

        if ($invoice->dueDate !== null && ! $isCreditNote) {
            $b->child($root, 'cbc:DueDate', $invoice->dueDate->format('Y-m-d'));
        }

        $b->child(
            $root,
            $isCreditNote ? 'cbc:CreditNoteTypeCode' : 'cbc:InvoiceTypeCode',
            (string) $invoice->typeCode,
        );

        if ($invoice->note !== null && $invoice->note !== '') {
            $b->child($root, 'cbc:Note', $invoice->note);
        }

        $b->child($root, 'cbc:DocumentCurrencyCode', $invoice->currency);

        if ($invoice->buyerReference !== null) {
            $b->child($root, 'cbc:BuyerReference', $invoice->buyerReference);
        }
    }

    private function appendReferences(DomBuilder $b, DOMElement $root, CanonicalInvoice $invoice): void
    {
        if ($invoice->orderReference !== null) {
            $orderReference = $b->child($root, 'cac:OrderReference');
            $b->child($orderReference, 'cbc:ID', $invoice->orderReference);
        }

        $preceding = $invoice->precedingInvoiceReference;

        if ($preceding !== null) {
            $billingReference = $b->child($root, 'cac:BillingReference');
            $documentReference = $b->child($billingReference, 'cac:InvoiceDocumentReference');
            $b->child($documentReference, 'cbc:ID', $preceding->number);

            if ($preceding->issueDate !== null) {
                $b->child($documentReference, 'cbc:IssueDate', $preceding->issueDate->format('Y-m-d'));
            }
        }
    }

    private function appendParty(DomBuilder $b, DOMElement $root, string $wrapper, Party $party): void
    {
        $partyWrapper = $b->child($root, $wrapper);
        $partyEl = $b->child($partyWrapper, 'cac:Party');

        if ($party->endpointId !== null && $party->endpointScheme !== null) {
            $b->child($partyEl, 'cbc:EndpointID', $party->endpointId, ['schemeID' => $party->endpointScheme]);
        }

        if ($party->legalRegistrationId !== null) {
            $identification = $b->child($partyEl, 'cac:PartyIdentification');
            $attrs = $party->legalRegistrationScheme !== null
                ? ['schemeID' => $party->legalRegistrationScheme]
                : [];
            $b->child($identification, 'cbc:ID', $party->legalRegistrationId, $attrs);
        }

        if ($party->tradingName !== null) {
            $partyName = $b->child($partyEl, 'cac:PartyName');
            $b->child($partyName, 'cbc:Name', $party->tradingName);
        }

        $this->appendPostalAddress($b, $partyEl, $party);

        if ($party->hasVatId()) {
            $taxScheme = $b->child($partyEl, 'cac:PartyTaxScheme');
            $b->child($taxScheme, 'cbc:CompanyID', $party->vatId);
            $scheme = $b->child($taxScheme, 'cac:TaxScheme');
            $b->child($scheme, 'cbc:ID', self::VAT_SCHEME);
        }

        $legalEntity = $b->child($partyEl, 'cac:PartyLegalEntity');
        $b->child($legalEntity, 'cbc:RegistrationName', $party->name);
        if ($party->legalRegistrationId !== null) {
            $attrs = $party->legalRegistrationScheme !== null
                ? ['schemeID' => $party->legalRegistrationScheme]
                : [];
            $b->child($legalEntity, 'cbc:CompanyID', $party->legalRegistrationId, $attrs);
        }

        $this->appendContact($b, $partyEl, $party);
    }

    private function appendPostalAddress(DomBuilder $b, DOMElement $partyEl, Party $party): void
    {
        $address = $b->child($partyEl, 'cac:PostalAddress');

        if ($party->street !== null) {
            $b->child($address, 'cbc:StreetName', $party->street);
        }

        if ($party->additionalStreet !== null) {
            $b->child($address, 'cbc:AdditionalStreetName', $party->additionalStreet);
        }

        if ($party->city !== null) {
            $b->child($address, 'cbc:CityName', $party->city);
        }

        if ($party->postalZone !== null) {
            $b->child($address, 'cbc:PostalZone', $party->postalZone);
        }

        if ($party->countrySubdivision !== null) {
            $b->child($address, 'cbc:CountrySubentity', $party->countrySubdivision);
        }

        $country = $b->child($address, 'cac:Country');
        $b->child($country, 'cbc:IdentificationCode', $party->countryCode);
    }

    private function appendContact(DomBuilder $b, DOMElement $partyEl, Party $party): void
    {
        if ($party->contactName === null && $party->contactPhone === null && $party->contactEmail === null) {
            return;
        }

        $contact = $b->child($partyEl, 'cac:Contact');

        if ($party->contactName !== null) {
            $b->child($contact, 'cbc:Name', $party->contactName);
        }

        if ($party->contactPhone !== null) {
            $b->child($contact, 'cbc:Telephone', $party->contactPhone);
        }

        if ($party->contactEmail !== null) {
            $b->child($contact, 'cbc:ElectronicMail', $party->contactEmail);
        }
    }

    private function appendPaymentMeans(DomBuilder $b, DOMElement $root, CanonicalInvoice $invoice): void
    {
        if ($invoice->paymentMeansCode === null) {
            return;
        }

        $means = $b->child($root, 'cac:PaymentMeans');
        $b->child($means, 'cbc:PaymentMeansCode', $invoice->paymentMeansCode);

        if ($invoice->paymentReference !== null) {
            $b->child($means, 'cbc:PaymentID', $invoice->paymentReference);
        }

        if ($invoice->payeeIban !== null) {
            $account = $b->child($means, 'cac:PayeeFinancialAccount');
            $b->child($account, 'cbc:ID', $invoice->payeeIban);

            if ($invoice->payeeBic !== null) {
                $institution = $b->child($account, 'cac:FinancialInstitutionBranch');
                $b->child($institution, 'cbc:ID', $invoice->payeeBic);
            }
        }
    }

    private function appendPaymentTerms(DomBuilder $b, DOMElement $root, CanonicalInvoice $invoice): void
    {
        if ($invoice->dueDate === null || $invoice->isCreditNote()) {
            return;
        }

        $terms = $b->child($root, 'cac:PaymentTerms');
        $b->child($terms, 'cbc:Note', 'Payment due by '.$invoice->dueDate->format('Y-m-d'));
    }

    private function appendTaxTotal(DomBuilder $b, DOMElement $root, CanonicalInvoice $invoice): void
    {
        $taxTotal = $b->child($root, 'cac:TaxTotal');
        $b->child($taxTotal, 'cbc:TaxAmount', Decimal::amount($invoice->taxAmount()), ['currencyID' => $invoice->currency]);

        foreach ($invoice->taxBreakdowns as $breakdown) {
            $this->appendTaxSubtotal($b, $taxTotal, $breakdown, $invoice->currency);
        }
    }

    private function appendTaxSubtotal(DomBuilder $b, DOMElement $taxTotal, TaxBreakdown $breakdown, string $currency): void
    {
        $subtotal = $b->child($taxTotal, 'cac:TaxSubtotal');
        $b->child($subtotal, 'cbc:TaxableAmount', Decimal::amount($breakdown->taxableAmount), ['currencyID' => $currency]);
        $b->child($subtotal, 'cbc:TaxAmount', Decimal::amount($breakdown->taxAmount), ['currencyID' => $currency]);

        $category = $b->child($subtotal, 'cac:TaxCategory');
        $b->child($category, 'cbc:ID', $breakdown->category);
        $b->child($category, 'cbc:Percent', Decimal::percent($breakdown->percent));

        if ($breakdown->exemptionReasonCode !== null) {
            $b->child($category, 'cbc:TaxExemptionReasonCode', $breakdown->exemptionReasonCode);
        }

        if ($breakdown->exemptionReason !== null) {
            $b->child($category, 'cbc:TaxExemptionReason', $breakdown->exemptionReason);
        }

        $scheme = $b->child($category, 'cac:TaxScheme');
        $b->child($scheme, 'cbc:ID', self::VAT_SCHEME);
    }

    private function appendMonetaryTotal(DomBuilder $b, DOMElement $root, CanonicalInvoice $invoice): void
    {
        $total = $b->child($root, 'cac:LegalMonetaryTotal');
        $currency = ['currencyID' => $invoice->currency];

        $b->child($total, 'cbc:LineExtensionAmount', Decimal::amount($invoice->lineExtensionAmount()), $currency);
        $b->child($total, 'cbc:TaxExclusiveAmount', Decimal::amount($invoice->taxExclusiveAmount()), $currency);
        $b->child($total, 'cbc:TaxInclusiveAmount', Decimal::amount($invoice->taxInclusiveAmount()), $currency);

        if ($invoice->prepaidAmount > 0) {
            $b->child($total, 'cbc:PrepaidAmount', Decimal::amount($invoice->prepaidAmount), $currency);
        }

        $b->child($total, 'cbc:PayableAmount', Decimal::amount($invoice->payableAmount()), $currency);
    }

    private function appendLines(DomBuilder $b, DOMElement $root, CanonicalInvoice $invoice, bool $isCreditNote): void
    {
        foreach ($invoice->lines as $line) {
            $this->appendLine($b, $root, $line, $invoice->currency, $isCreditNote);
        }
    }

    private function appendLine(DomBuilder $b, DOMElement $root, LineItem $line, string $currency, bool $isCreditNote): void
    {
        $lineEl = $b->child($root, $isCreditNote ? 'cac:CreditNoteLine' : 'cac:InvoiceLine');
        $b->child($lineEl, 'cbc:ID', $line->id);

        $b->child(
            $lineEl,
            $isCreditNote ? 'cbc:CreditedQuantity' : 'cbc:InvoicedQuantity',
            Decimal::quantity($line->quantity),
            ['unitCode' => $line->unitCode],
        );

        $b->child($lineEl, 'cbc:LineExtensionAmount', Decimal::amount($line->lineExtensionAmount), ['currencyID' => $currency]);

        $item = $b->child($lineEl, 'cac:Item');
        if ($line->description !== null) {
            $b->child($item, 'cbc:Description', $line->description);
        }
        $b->child($item, 'cbc:Name', $line->name);

        if ($line->sellerItemId !== null) {
            $sellersId = $b->child($item, 'cac:SellersItemIdentification');
            $b->child($sellersId, 'cbc:ID', $line->sellerItemId);
        }

        if ($line->buyerItemId !== null) {
            $buyersId = $b->child($item, 'cac:BuyersItemIdentification');
            $b->child($buyersId, 'cbc:ID', $line->buyerItemId);
        }

        $taxCategory = $b->child($item, 'cac:ClassifiedTaxCategory');
        $b->child($taxCategory, 'cbc:ID', $line->taxCategory);
        $b->child($taxCategory, 'cbc:Percent', Decimal::percent($line->taxPercent));
        $scheme = $b->child($taxCategory, 'cac:TaxScheme');
        $b->child($scheme, 'cbc:ID', self::VAT_SCHEME);

        $price = $b->child($lineEl, 'cac:Price');
        $b->child($price, 'cbc:PriceAmount', Decimal::amount($line->netPrice), ['currencyID' => $currency]);

        if ($line->baseQuantity !== null) {
            $b->child($price, 'cbc:BaseQuantity', Decimal::quantity($line->baseQuantity), ['unitCode' => $line->unitCode]);
        }
    }

    private function filename(CanonicalInvoice $invoice): string
    {
        $safe = preg_replace('/[^A-Za-z0-9._-]/', '-', $invoice->number) ?? 'invoice';

        return $safe.'.xml';
    }
}
