<?php

namespace App\Enums;

use App\Enums\Concerns\HasLookupCode;

/**
 * Rows of the attendance_statuses lookup table. Case values are the row IDs.
 */
enum AttendanceStatus: int
{
    use HasLookupCode;

    case Present = 1;
    case Absent = 2;
    case Late = 3;
    case Excused = 4;

    /**
     * Whether a day with this status counts as worked, so the items billed on
     * it can go into a payout when the clinic requires attendance.
     */
    public function countsAsWorked(): bool
    {
        return match ($this) {
            self::Present, self::Late => true,
            self::Absent, self::Excused => false,
        };
    }
}
