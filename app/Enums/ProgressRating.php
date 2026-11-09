<?php

namespace App\Enums;

use App\Enums\Concerns\HasLookupCode;

/**
 * Rows of the progress_ratings lookup table. Case values are the row IDs.
 * How a patient is doing compared with the previous note.
 */
enum ProgressRating: int
{
    use HasLookupCode;

    case Baseline = 1;
    case Improved = 2;
    case Unchanged = 3;
    case Declined = 4;
    case Resolved = 5;

    public function label(): string
    {
        return match ($this) {
            self::Baseline => 'Baseline',
            self::Improved => 'Improved',
            self::Unchanged => 'No change',
            self::Declined => 'Declined',
            self::Resolved => 'Resolved',
        };
    }

    /**
     * Which way the note moves the patient: 1 better, 0 flat, -1 worse.
     * Used for the trend shown above a patient's notes.
     */
    public function direction(): int
    {
        return match ($this) {
            self::Improved, self::Resolved => 1,
            self::Unchanged, self::Baseline => 0,
            self::Declined => -1,
        };
    }
}
