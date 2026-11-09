<?php

namespace App\Enums;

use App\Enums\Concerns\HasLookupCode;

/**
 * Rows of the session_statuses lookup table. Case values are the row IDs.
 */
enum SessionStatus: int
{
    use HasLookupCode;

    case Pending = 1;
    case Ongoing = 2;
    case Done = 3;
    case Cancelled = 4;

    /**
     * Determine whether a session may move from this status to the given one.
     */
    public function canTransitionTo(self $status): bool
    {
        return in_array($status, match ($this) {
            self::Pending => [self::Ongoing, self::Cancelled],
            self::Ongoing => [self::Done, self::Cancelled],
            self::Done, self::Cancelled => [],
        }, true);
    }

    /**
     * Determine whether the session's details and patients may still change.
     */
    public function isOpen(): bool
    {
        return in_array($this, [self::Pending, self::Ongoing], true);
    }
}
