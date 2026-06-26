<?php

declare(strict_types=1);

namespace Vimatech\EInvoicing\Dtos;

/**
 * A neutral representation of a trading party (seller or buyer).
 *
 * Maps onto EN 16931 business groups BG-4 (Seller) and BG-7 (Buyer) without
 * leaking any syntax-specific concepts.
 */
final readonly class Party
{
    /**
     * @param  string  $name  Trading / registration name (BT-27 / BT-44).
     * @param  string  $countryCode  ISO 3166-1 alpha-2 country code (BT-40 / BT-55).
     * @param  string|null  $endpointId  Electronic address used for routing (BT-34 / BT-49).
     * @param  string|null  $endpointScheme  EAS scheme id of the endpoint, e.g. "0208", "9925".
     * @param  string|null  $vatId  VAT identifier, e.g. "BE0123456789" (BT-31 / BT-48).
     * @param  string|null  $legalRegistrationId  Legal registration number (BT-30 / BT-47).
     * @param  string|null  $legalRegistrationScheme  ICD scheme for the legal id, e.g. "0208".
     * @param  string|null  $tradingName  Optional trading name distinct from the legal name.
     * @param  string|null  $street  Address line 1 (BT-35 / BT-50).
     * @param  string|null  $additionalStreet  Address line 2 (BT-36 / BT-51).
     * @param  string|null  $city  City (BT-37 / BT-52).
     * @param  string|null  $postalZone  Post code (BT-38 / BT-53).
     * @param  string|null  $countrySubdivision  Region / county (BT-39 / BT-54).
     * @param  string|null  $contactName  Contact point name (BT-41 / BT-56).
     * @param  string|null  $contactPhone  Contact telephone (BT-42 / BT-57).
     * @param  string|null  $contactEmail  Contact email (BT-43 / BT-58).
     */
    public function __construct(
        public string $name,
        public string $countryCode,
        public ?string $endpointId = null,
        public ?string $endpointScheme = null,
        public ?string $vatId = null,
        public ?string $legalRegistrationId = null,
        public ?string $legalRegistrationScheme = null,
        public ?string $tradingName = null,
        public ?string $street = null,
        public ?string $additionalStreet = null,
        public ?string $city = null,
        public ?string $postalZone = null,
        public ?string $countrySubdivision = null,
        public ?string $contactName = null,
        public ?string $contactPhone = null,
        public ?string $contactEmail = null,
    ) {}

    public function hasElectronicAddress(): bool
    {
        return $this->endpointId !== null && $this->endpointScheme !== null;
    }

    public function hasVatId(): bool
    {
        return $this->vatId !== null && $this->vatId !== '';
    }
}
