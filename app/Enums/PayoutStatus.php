<?php

namespace App\Enums;

use App\Enums\Concerns\HasLookupCode;

/**
 * Rows of the payout_statuses lookup table. Case values are the row IDs.
 */
enum PayoutStatus: int
{
    use HasLookupCode;

    case Draft = 1;
    case Paid = 2;
    case Cancelled = 3;

    /**
     * Determine whether a payout may move from this status to the given one.
     * Cancelling releases the payout's items so they can be paid out again.
     */
    public function canTransitionTo(self $status): bool
    {
        return match ($this) {
            self::Draft => $status === self::Paid || $status === self::Cancelled,
            self::Paid => $status === self::Cancelled,
            self::Cancelled => false,
        };
    }
}
