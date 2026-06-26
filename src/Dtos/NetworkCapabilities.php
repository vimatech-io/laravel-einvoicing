<?php

declare(strict_types=1);

namespace Vimatech\EInvoicing\Dtos;

use Vimatech\EInvoicing\Enums\Format;

/**
 * Declares what a given network driver can do, so callers can negotiate the
 * right format and operations without try/catch probing.
 */
final readonly class NetworkCapabilities
{
    /**
     * @param  string  $network  Network key.
     * @param  list<Format>  $formats  Formats the network accepts for outbound documents.
     * @param  list<string>  $countries  ISO 3166-1 alpha-2 countries the network can reach.
     * @param  bool  $canSend  Whether outbound dispatch is supported.
     * @param  bool  $canFetchStatus  Whether lifecycle polling is supported.
     * @param  bool  $canReceive  Whether inbound retrieval is supported.
     */
    public function __construct(
        public string $network,
        public array $formats,
        public array $countries = [],
        public bool $canSend = true,
        public bool $canFetchStatus = true,
        public bool $canReceive = false,
    ) {}

    public function supports(Format $format): bool
    {
        return in_array($format, $this->formats, true);
    }

    public function reaches(string $country): bool
    {
        return $this->countries === [] || in_array(strtoupper($country), $this->countries, true);
    }
}
