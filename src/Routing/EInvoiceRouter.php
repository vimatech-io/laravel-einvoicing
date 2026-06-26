<?php

declare(strict_types=1);

namespace Vimatech\EInvoicing\Routing;

use Closure;
use Vimatech\EInvoicing\Contracts\EInvoiceNetwork;
use Vimatech\EInvoicing\Dtos\CanonicalInvoice;
use Vimatech\EInvoicing\Exceptions\UnsupportedCountry;
use Vimatech\EInvoicing\Networks\NetworkManager;

/**
 * Resolves the network responsible for a destination country.
 *
 * Resolution order:
 *   1. a registered tenant/override resolver (if it returns a network key);
 *   2. the configured country → network routing map;
 *   3. the configured fallback network;
 *   4. otherwise a hard {@see UnsupportedCountry} failure.
 */
final class EInvoiceRouter
{
    /** @var (Closure(string, ?CanonicalInvoice): ?string)|null */
    private ?Closure $override = null;

    /**
     * @param  array<string, string>  $routes  Upper-case country code => network key.
     */
    public function __construct(
        private readonly NetworkManager $networks,
        private array $routes,
        private readonly ?string $fallback = null,
    ) {}

    /**
     * Register a per-tenant (or per-invoice) override resolver.
     *
     * The resolver receives the destination country and, when available, the
     * invoice. Returning a network key wins over the static map; returning null
     * defers to the normal resolution chain.
     *
     * @param  Closure(string, ?CanonicalInvoice): ?string  $resolver
     */
    public function overrideUsing(Closure $resolver): self
    {
        $this->override = $resolver;

        return $this;
    }

    /**
     * Resolve the network for a country code.
     *
     * @throws UnsupportedCountry
     */
    public function routeFor(string $country, ?CanonicalInvoice $invoice = null): EInvoiceNetwork
    {
        $country = strtoupper($country);

        $key = $this->resolveKey($country, $invoice);

        if ($key === null) {
            throw new UnsupportedCountry($country);
        }

        return $this->networks->network($key);
    }

    /**
     * Resolve the network for an invoice (by its destination country).
     *
     * @throws UnsupportedCountry
     */
    public function route(CanonicalInvoice $invoice): EInvoiceNetwork
    {
        return $this->routeFor($invoice->destinationCountry(), $invoice);
    }

    public function supports(string $country): bool
    {
        return $this->resolveKey(strtoupper($country), null) !== null;
    }

    private function resolveKey(string $country, ?CanonicalInvoice $invoice): ?string
    {
        if ($this->override !== null) {
            $overridden = ($this->override)($country, $invoice);
            if ($overridden !== null) {
                return $overridden;
            }
        }

        return $this->routes[$country] ?? $this->fallback;
    }
}
