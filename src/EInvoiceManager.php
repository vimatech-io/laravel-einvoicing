<?php

declare(strict_types=1);

namespace Vimatech\EInvoicing;

use Illuminate\Contracts\Events\Dispatcher;
use Vimatech\EInvoicing\Contracts\EInvoiceNetwork;
use Vimatech\EInvoicing\Contracts\FormatGenerator;
use Vimatech\EInvoicing\Dtos\CanonicalInvoice;
use Vimatech\EInvoicing\Dtos\DispatchResult;
use Vimatech\EInvoicing\Dtos\GeneratedDocument;
use Vimatech\EInvoicing\Dtos\InboundDocument;
use Vimatech\EInvoicing\Enums\Format;
use Vimatech\EInvoicing\Events\EInvoiceDelivered;
use Vimatech\EInvoicing\Events\EInvoiceDispatched;
use Vimatech\EInvoicing\Events\EInvoiceGenerated;
use Vimatech\EInvoicing\Events\EInvoiceReceived;
use Vimatech\EInvoicing\Events\EInvoiceRejected;
use Vimatech\EInvoicing\Formats\CiiGenerator;
use Vimatech\EInvoicing\Formats\FacturXGenerator;
use Vimatech\EInvoicing\Formats\UblGenerator;
use Vimatech\EInvoicing\Networks\FakeDriver;
use Vimatech\EInvoicing\Networks\NetworkManager;
use Vimatech\EInvoicing\Routing\EInvoiceRouter;

/**
 * The package's public entry point (resolved behind the EInvoice facade).
 *
 * It composes the format generators, the network manager and the country
 * router, and is the single place lifecycle events are dispatched from.
 */
final class EInvoiceManager
{
    /** @var array<string, FormatGenerator> */
    private array $generators = [];

    public function __construct(
        private readonly NetworkManager $networks,
        private readonly EInvoiceRouter $router,
        private readonly Dispatcher $events,
        private readonly Format $defaultFormat = Format::Ubl,
    ) {}

    /**
     * Resolve the generator for a format. Chain ->generate($invoice) to render.
     */
    public function format(Format $format): FormatGenerator
    {
        return $this->generators[$format->value] ??= match ($format) {
            Format::Ubl => new UblGenerator,
            Format::Cii => new CiiGenerator,
            Format::FacturX => new FacturXGenerator,
        };
    }

    /**
     * Render an invoice and dispatch the EInvoiceGenerated event.
     */
    public function generate(CanonicalInvoice $invoice, ?Format $format = null): GeneratedDocument
    {
        $document = $this->format($format ?? $this->defaultFormat)->generate($invoice);

        $this->events->dispatch(new EInvoiceGenerated($invoice, $document));

        return $document;
    }

    /**
     * Resolve a network by its configuration key.
     */
    public function network(string $key): EInvoiceNetwork
    {
        return $this->networks->network($key);
    }

    /**
     * Resolve the network responsible for a destination country.
     */
    public function route(string $country): EInvoiceNetwork
    {
        return $this->router->routeFor($country);
    }

    public function router(): EInvoiceRouter
    {
        return $this->router;
    }

    public function networks(): NetworkManager
    {
        return $this->networks;
    }

    /**
     * High-level: render (if needed), route by buyer country (or use an explicit
     * network), transmit, and dispatch lifecycle events.
     */
    public function send(
        CanonicalInvoice $invoice,
        ?Format $format = null,
        ?string $networkKey = null,
    ): DispatchResult {
        $document = $this->generate($invoice, $format);

        $network = $networkKey !== null
            ? $this->networks->network($networkKey)
            : $this->router->route($invoice);

        $result = $network->send($document, $invoice);

        $this->events->dispatch(new EInvoiceDispatched($invoice, $document, $result));

        if ($result->status->isSuccessful()) {
            $this->events->dispatch(new EInvoiceDelivered($result, $invoice));
        } else {
            $this->events->dispatch(new EInvoiceRejected($result, $invoice));
        }

        return $result;
    }

    /**
     * Pull inbound documents from a network and dispatch EInvoiceReceived per item.
     *
     * @return list<InboundDocument>
     */
    public function receive(string $networkKey): array
    {
        $documents = $this->networks->network($networkKey)->receive();

        foreach ($documents as $document) {
            $this->events->dispatch(new EInvoiceReceived($document));
        }

        return $documents;
    }

    /**
     * Swap a network for an in-memory fake and return it for assertions.
     */
    public function fake(string $networkKey): FakeDriver
    {
        return $this->networks->fake($networkKey);
    }
}
