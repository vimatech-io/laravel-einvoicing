<?php

declare(strict_types=1);

namespace Vimatech\EInvoicing\Facades;

use Illuminate\Support\Facades\Facade;
use Vimatech\EInvoicing\EInvoiceManager;

/**
 * @method static \Vimatech\EInvoicing\Contracts\FormatGenerator format(\Vimatech\EInvoicing\Enums\Format $format)
 * @method static \Vimatech\EInvoicing\Dtos\GeneratedDocument generate(\Vimatech\EInvoicing\Dtos\CanonicalInvoice $invoice, ?\Vimatech\EInvoicing\Enums\Format $format = null)
 * @method static \Vimatech\EInvoicing\Contracts\EInvoiceNetwork network(string $key)
 * @method static \Vimatech\EInvoicing\Contracts\EInvoiceNetwork route(string $country)
 * @method static \Vimatech\EInvoicing\Routing\EInvoiceRouter router()
 * @method static \Vimatech\EInvoicing\Networks\NetworkManager networks()
 * @method static \Vimatech\EInvoicing\Dtos\DispatchResult send(\Vimatech\EInvoicing\Dtos\CanonicalInvoice $invoice, ?\Vimatech\EInvoicing\Enums\Format $format = null, ?string $networkKey = null)
 * @method static list<\Vimatech\EInvoicing\Dtos\InboundDocument> receive(string $networkKey)
 * @method static \Vimatech\EInvoicing\Networks\FakeDriver fake(string $networkKey)
 *
 * @see EInvoiceManager
 */
final class EInvoice extends Facade
{
    protected static function getFacadeAccessor(): string
    {
        return EInvoiceManager::class;
    }
}
