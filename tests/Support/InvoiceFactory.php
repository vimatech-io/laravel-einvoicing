<?php

declare(strict_types=1);

namespace Vimatech\EInvoicing\Tests\Support;

use DateTimeImmutable;
use Vimatech\EInvoicing\Dtos\CanonicalInvoice;
use Vimatech\EInvoicing\Dtos\LineItem;
use Vimatech\EInvoicing\Dtos\Party;
use Vimatech\EInvoicing\Dtos\PrecedingInvoiceReference;
use Vimatech\EInvoicing\Dtos\TaxBreakdown;

/**
 * Deterministic sample invoices for the test suite and golden-file seeding.
 */
final class InvoiceFactory
{
    public static function seller(): Party
    {
        return new Party(
            name: 'SupplierTradingName Ltd.',
            countryCode: 'BE',
            endpointId: '0123456789',
            endpointScheme: '0208',
            vatId: 'BE0123456789',
            legalRegistrationId: '0123456789',
            legalRegistrationScheme: '0208',
            street: 'Main street 1, Po Box 351',
            city: 'Brussels',
            postalZone: '1050',
            contactName: 'John Doe',
            contactPhone: '+32 25 21 12 34',
            contactEmail: 'john.doe@supplier.test',
        );
    }

    public static function buyer(): Party
    {
        return new Party(
            name: 'BuyerTradingName AS',
            countryCode: 'BE',
            endpointId: '9876543210',
            endpointScheme: '0208',
            vatId: 'BE9876543210',
            legalRegistrationId: '9876543210',
            legalRegistrationScheme: '0208',
            street: 'Hovedgatan 32, Po Box 5467',
            city: 'Antwerp',
            postalZone: '2000',
        );
    }

    public static function standardInvoice(): CanonicalInvoice
    {
        $lines = [
            new LineItem(
                id: '1',
                name: 'Laptop computer',
                quantity: 5.0,
                netPrice: 200.0,
                lineExtensionAmount: 1000.0,
                taxCategory: 'S',
                taxPercent: 21.0,
                sellerItemId: 'SKU-LAPTOP',
            ),
            new LineItem(
                id: '2',
                name: 'Wireless mouse',
                quantity: 10.0,
                netPrice: 25.0,
                lineExtensionAmount: 250.0,
                taxCategory: 'S',
                taxPercent: 21.0,
            ),
        ];

        return new CanonicalInvoice(
            number: 'INV-2024-0001',
            issueDate: new DateTimeImmutable('2024-01-15'),
            currency: 'EUR',
            seller: self::seller(),
            buyer: self::buyer(),
            lines: $lines,
            taxBreakdowns: [
                new TaxBreakdown(
                    category: 'S',
                    percent: 21.0,
                    taxableAmount: 1250.0,
                    taxAmount: 262.5,
                ),
            ],
            dueDate: new DateTimeImmutable('2024-02-14'),
            buyerReference: 'PO-98765',
            orderReference: 'PO-98765',
            note: 'Thank you for your business.',
            paymentMeansCode: '30',
            payeeIban: 'BE50000000000000',
            payeeBic: 'GEBABEBB',
            paymentReference: 'INV-2024-0001',
        );
    }

    public static function creditNote(): CanonicalInvoice
    {
        $invoice = self::standardInvoice();

        return new CanonicalInvoice(
            number: 'CN-2024-0001',
            issueDate: $invoice->issueDate,
            currency: $invoice->currency,
            seller: $invoice->seller,
            buyer: $invoice->buyer,
            lines: [
                new LineItem(
                    id: '1',
                    name: 'Laptop computer',
                    quantity: 1.0,
                    netPrice: 200.0,
                    lineExtensionAmount: 200.0,
                    taxCategory: 'S',
                    taxPercent: 21.0,
                ),
            ],
            taxBreakdowns: [
                new TaxBreakdown(
                    category: 'S',
                    percent: 21.0,
                    taxableAmount: 200.0,
                    taxAmount: 42.0,
                ),
            ],
            typeCode: CanonicalInvoice::TYPE_CREDIT_NOTE,
            buyerReference: 'PO-98765',
            note: 'Credit for returned item.',
        );
    }

    public static function creditNoteCorrecting(): CanonicalInvoice
    {
        $creditNote = self::creditNote();

        return new CanonicalInvoice(
            number: $creditNote->number,
            issueDate: $creditNote->issueDate,
            currency: $creditNote->currency,
            seller: $creditNote->seller,
            buyer: $creditNote->buyer,
            lines: $creditNote->lines,
            taxBreakdowns: $creditNote->taxBreakdowns,
            typeCode: $creditNote->typeCode,
            buyerReference: $creditNote->buyerReference,
            note: $creditNote->note,
            precedingInvoiceReference: new PrecedingInvoiceReference(
                number: 'INV-2024-0001',
                issueDate: new DateTimeImmutable('2024-01-15'),
            ),
        );
    }
}
