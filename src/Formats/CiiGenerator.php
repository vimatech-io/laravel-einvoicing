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
use Vimatech\EInvoicing\Formats\Support\Decimal;
use Vimatech\EInvoicing\Formats\Support\DomBuilder;
use Vimatech\EInvoicing\Formats\Support\InvoiceValidator;

/**
 * Native generator for UN/CEFACT Cross Industry Invoice (CII), EN 16931
 * compliant. Implemented purely with ext-dom.
 *
 * This emits the EN 16931 core subset shared with Factur-X; the same XML can
 * later be embedded into a PDF/A-3 by a dedicated Factur-X module.
 */
final class CiiGenerator implements FormatGenerator
{
    private const RSM = 'urn:un:unece:uncefact:data:standard:CrossIndustryInvoice:100';

    private const RAM = 'urn:un:unece:uncefact:data:standard:ReusableAggregateBusinessInformationEntity:100';

    private const UDT = 'urn:un:unece:uncefact:data:standard:UnqualifiedDataType:100';

    private const QDT = 'urn:un:unece:uncefact:data:standard:QualifiedDataType:100';

    public const GUIDELINE_ID = 'urn:cen.eu:en16931:2017';

    private const VAT_TYPE = 'VAT';

    public function format(): Format
    {
        return Format::Cii;
    }

    public function generate(CanonicalInvoice $invoice): GeneratedDocument
    {
        InvoiceValidator::assert($invoice);

        $builder = new DomBuilder('rsm:CrossIndustryInvoice', self::RSM, [
            'rsm' => self::RSM,
            'ram' => self::RAM,
            'qdt' => self::QDT,
            'udt' => self::UDT,
        ]);

        $root = $builder->root();

        $this->appendContext($builder, $root);
        $this->appendDocument($builder, $root, $invoice);
        $this->appendTransaction($builder, $root, $invoice);

        return new GeneratedDocument(
            format: Format::Cii,
            contents: $builder->toXml(),
            mimeType: Format::Cii->mimeType(),
            filename: $this->filename($invoice),
            profile: self::GUIDELINE_ID,
            invoiceNumber: $invoice->number,
        );
    }

    private function appendContext(DomBuilder $b, DOMElement $root): void
    {
        $context = $b->child($root, 'rsm:ExchangedDocumentContext');
        $guideline = $b->child($context, 'ram:GuidelineSpecifiedDocumentContextParameter');
        $b->child($guideline, 'ram:ID', self::GUIDELINE_ID);
    }

    private function appendDocument(DomBuilder $b, DOMElement $root, CanonicalInvoice $invoice): void
    {
        $document = $b->child($root, 'rsm:ExchangedDocument');
        $b->child($document, 'ram:ID', $invoice->number);
        $b->child($document, 'ram:TypeCode', (string) $invoice->typeCode);

        $issue = $b->child($document, 'ram:IssueDateTime');
        $b->child($issue, 'udt:DateTimeString', $invoice->issueDate->format('Ymd'), ['format' => '102']);

        if ($invoice->note !== null && $invoice->note !== '') {
            $note = $b->child($document, 'ram:IncludedNote');
            $b->child($note, 'ram:Content', $invoice->note);
        }
    }

    private function appendTransaction(DomBuilder $b, DOMElement $root, CanonicalInvoice $invoice): void
    {
        $transaction = $b->child($root, 'rsm:SupplyChainTradeTransaction');

        foreach ($invoice->lines as $line) {
            $this->appendLine($b, $transaction, $line);
        }

        $this->appendHeaderAgreement($b, $transaction, $invoice);
        $b->child($transaction, 'ram:ApplicableHeaderTradeDelivery');
        $this->appendHeaderSettlement($b, $transaction, $invoice);
    }

    private function appendLine(DomBuilder $b, DOMElement $transaction, LineItem $line): void
    {
        $item = $b->child($transaction, 'ram:IncludedSupplyChainTradeLineItem');

        $lineDoc = $b->child($item, 'ram:AssociatedDocumentLineDocument');
        $b->child($lineDoc, 'ram:LineID', $line->id);

        $product = $b->child($item, 'ram:SpecifiedTradeProduct');
        if ($line->sellerItemId !== null) {
            $b->child($product, 'ram:SellerAssignedID', $line->sellerItemId);
        }
        $b->child($product, 'ram:Name', $line->name);
        if ($line->description !== null) {
            $b->child($product, 'ram:Description', $line->description);
        }

        $agreement = $b->child($item, 'ram:SpecifiedLineTradeAgreement');
        $price = $b->child($agreement, 'ram:NetPriceProductTradePrice');
        $b->child($price, 'ram:ChargeAmount', Decimal::amount($line->netPrice));

        $delivery = $b->child($item, 'ram:SpecifiedLineTradeDelivery');
        $b->child($delivery, 'ram:BilledQuantity', Decimal::quantity($line->quantity), ['unitCode' => $line->unitCode]);

        $settlement = $b->child($item, 'ram:SpecifiedLineTradeSettlement');
        $tax = $b->child($settlement, 'ram:ApplicableTradeTax');
        $b->child($tax, 'ram:TypeCode', self::VAT_TYPE);
        $b->child($tax, 'ram:CategoryCode', $line->taxCategory);
        $b->child($tax, 'ram:RateApplicablePercent', Decimal::percent($line->taxPercent));

        $summation = $b->child($settlement, 'ram:SpecifiedTradeSettlementLineMonetarySummation');
        $b->child($summation, 'ram:LineTotalAmount', Decimal::amount($line->lineExtensionAmount));
    }

    private function appendHeaderAgreement(DomBuilder $b, DOMElement $transaction, CanonicalInvoice $invoice): void
    {
        $agreement = $b->child($transaction, 'ram:ApplicableHeaderTradeAgreement');

        if ($invoice->buyerReference !== null) {
            $b->child($agreement, 'ram:BuyerReference', $invoice->buyerReference);
        }

        $this->appendTradeParty($b, $agreement, 'ram:SellerTradeParty', $invoice->seller);
        $this->appendTradeParty($b, $agreement, 'ram:BuyerTradeParty', $invoice->buyer);

        if ($invoice->orderReference !== null) {
            $order = $b->child($agreement, 'ram:BuyerOrderReferencedDocument');
            $b->child($order, 'ram:IssuerAssignedID', $invoice->orderReference);
        }
    }

    private function appendTradeParty(DomBuilder $b, DOMElement $agreement, string $wrapper, Party $party): void
    {
        $partyEl = $b->child($agreement, $wrapper);
        $b->child($partyEl, 'ram:Name', $party->name);

        if ($party->legalRegistrationId !== null) {
            $legal = $b->child($partyEl, 'ram:SpecifiedLegalOrganization');
            $attrs = $party->legalRegistrationScheme !== null
                ? ['schemeID' => $party->legalRegistrationScheme]
                : [];
            $b->child($legal, 'ram:ID', $party->legalRegistrationId, $attrs);
        }

        $address = $b->child($partyEl, 'ram:PostalTradeAddress');
        if ($party->postalZone !== null) {
            $b->child($address, 'ram:PostcodeCode', $party->postalZone);
        }
        if ($party->street !== null) {
            $b->child($address, 'ram:LineOne', $party->street);
        }
        if ($party->additionalStreet !== null) {
            $b->child($address, 'ram:LineTwo', $party->additionalStreet);
        }
        if ($party->city !== null) {
            $b->child($address, 'ram:CityName', $party->city);
        }
        $b->child($address, 'ram:CountryID', $party->countryCode);

        if ($party->contactEmail !== null) {
            $communication = $b->child($partyEl, 'ram:URIUniversalCommunication');
            $b->child($communication, 'ram:URIID', $party->contactEmail, ['schemeID' => 'EM']);
        }

        if ($party->hasVatId()) {
            $registration = $b->child($partyEl, 'ram:SpecifiedTaxRegistration');
            $b->child($registration, 'ram:ID', $party->vatId, ['schemeID' => 'VA']);
        }
    }

    private function appendHeaderSettlement(DomBuilder $b, DOMElement $transaction, CanonicalInvoice $invoice): void
    {
        $settlement = $b->child($transaction, 'ram:ApplicableHeaderTradeSettlement');

        if ($invoice->paymentReference !== null) {
            $b->child($settlement, 'ram:PaymentReference', $invoice->paymentReference);
        }

        $b->child($settlement, 'ram:InvoiceCurrencyCode', $invoice->currency);

        if ($invoice->paymentMeansCode !== null) {
            $means = $b->child($settlement, 'ram:SpecifiedTradeSettlementPaymentMeans');
            $b->child($means, 'ram:TypeCode', $invoice->paymentMeansCode);

            if ($invoice->payeeIban !== null) {
                $account = $b->child($means, 'ram:PayeePartyCreditorFinancialAccount');
                $b->child($account, 'ram:IBANID', $invoice->payeeIban);
            }
        }

        foreach ($invoice->taxBreakdowns as $breakdown) {
            $this->appendHeaderTax($b, $settlement, $breakdown, $invoice->currency);
        }

        if ($invoice->dueDate !== null) {
            $terms = $b->child($settlement, 'ram:SpecifiedTradePaymentTerms');
            $due = $b->child($terms, 'ram:DueDateDateTime');
            $b->child($due, 'udt:DateTimeString', $invoice->dueDate->format('Ymd'), ['format' => '102']);
        }

        $this->appendSummation($b, $settlement, $invoice);
        $this->appendPrecedingInvoice($b, $settlement, $invoice);
    }

    // HeaderTradeSettlementType sequences ram:InvoiceReferencedDocument *after* the monetary
    // summation, unlike every other reference in the document.
    private function appendPrecedingInvoice(DomBuilder $b, DOMElement $settlement, CanonicalInvoice $invoice): void
    {
        $preceding = $invoice->precedingInvoiceReference;

        if ($preceding === null) {
            return;
        }

        $referenced = $b->child($settlement, 'ram:InvoiceReferencedDocument');
        $b->child($referenced, 'ram:IssuerAssignedID', $preceding->number);

        if ($preceding->issueDate !== null) {
            $issue = $b->child($referenced, 'ram:FormattedIssueDateTime');
            $b->child($issue, 'qdt:DateTimeString', $preceding->issueDate->format('Ymd'), ['format' => '102']);
        }
    }

    private function appendHeaderTax(DomBuilder $b, DOMElement $settlement, TaxBreakdown $breakdown, string $currency): void
    {
        $tax = $b->child($settlement, 'ram:ApplicableTradeTax');
        $b->child($tax, 'ram:CalculatedAmount', Decimal::amount($breakdown->taxAmount));
        $b->child($tax, 'ram:TypeCode', self::VAT_TYPE);

        if ($breakdown->exemptionReason !== null) {
            $b->child($tax, 'ram:ExemptionReason', $breakdown->exemptionReason);
        }

        $b->child($tax, 'ram:BasisAmount', Decimal::amount($breakdown->taxableAmount));
        $b->child($tax, 'ram:CategoryCode', $breakdown->category);

        if ($breakdown->exemptionReasonCode !== null) {
            $b->child($tax, 'ram:ExemptionReasonCode', $breakdown->exemptionReasonCode);
        }

        $b->child($tax, 'ram:RateApplicablePercent', Decimal::percent($breakdown->percent));
    }

    private function appendSummation(DomBuilder $b, DOMElement $settlement, CanonicalInvoice $invoice): void
    {
        $summation = $b->child($settlement, 'ram:SpecifiedTradeSettlementHeaderMonetarySummation');
        $b->child($summation, 'ram:LineTotalAmount', Decimal::amount($invoice->lineExtensionAmount()));
        $b->child($summation, 'ram:TaxBasisTotalAmount', Decimal::amount($invoice->taxExclusiveAmount()));
        $b->child($summation, 'ram:TaxTotalAmount', Decimal::amount($invoice->taxAmount()), ['currencyID' => $invoice->currency]);
        $b->child($summation, 'ram:GrandTotalAmount', Decimal::amount($invoice->taxInclusiveAmount()));

        if ($invoice->prepaidAmount > 0) {
            $b->child($summation, 'ram:TotalPrepaidAmount', Decimal::amount($invoice->prepaidAmount));
        }

        $b->child($summation, 'ram:DuePayableAmount', Decimal::amount($invoice->payableAmount()));
    }

    private function filename(CanonicalInvoice $invoice): string
    {
        $safe = preg_replace('/[^A-Za-z0-9._-]/', '-', $invoice->number) ?? 'invoice';

        return $safe.'-cii.xml';
    }
}
