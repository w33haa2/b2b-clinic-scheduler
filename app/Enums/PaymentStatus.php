<?php

namespace App\Enums;

use App\Enums\Concerns\HasLookupCode;

/**
 * Rows of the payment_statuses lookup table. Case values are the row IDs.
 */
enum PaymentStatus: int
{
    use HasLookupCode;

    case Pending = 1;
    case Done = 2;
    case Refunded = 3;

    /**
     * Determine whether a session member's payment may move from this status to the given one.
     */
    public function canTransitionTo(self $status): bool
    {
        return match ($this) {
            self::Pending => $status === self::Done,
            self::Done => $status === self::Refunded,
            self::Refunded => false,
        };
    }
}
