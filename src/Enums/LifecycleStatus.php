<?php

declare(strict_types=1);

namespace Vimatech\EInvoicing\Enums;

/**
 * Network-agnostic lifecycle of a dispatched e-invoice.
 *
 * Drivers map their provider-specific states onto these canonical values so
 * consuming applications never need to understand a vendor's vocabulary.
 */
enum LifecycleStatus: string
{
    /** Accepted by the access point / PDP but not yet handed to the network. */
    case Submitted = 'submitted';

    /** In transit on the network. */
    case InTransit = 'in_transit';

    /** Delivered to the recipient's access point. */
    case Delivered = 'delivered';

    /** Functionally accepted by the recipient (e.g. a positive MLR/invoice response). */
    case Accepted = 'accepted';

    /** Functionally rejected by the recipient. */
    case Rejected = 'rejected';

    /** Permanently failed to transmit. */
    case Failed = 'failed';

    /** State could not be determined. */
    case Unknown = 'unknown';

    /**
     * Whether this status represents a terminal outcome.
     */
    public function isTerminal(): bool
    {
        return match ($this) {
            self::Accepted, self::Rejected, self::Failed => true,
            default => false,
        };
    }

    /**
     * Whether this status represents a successful hand-off (not a failure).
     */
    public function isSuccessful(): bool
    {
        return match ($this) {
            self::Rejected, self::Failed, self::Unknown => false,
            default => true,
        };
    }
}
