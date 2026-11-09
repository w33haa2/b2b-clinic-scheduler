<?php

namespace App\Enums;

use App\Enums\Concerns\HasLookupCode;

/**
 * Rows of the discount_targets lookup table. Case values are the row IDs.
 * The target is the side of the split that pays for the discount.
 */
enum DiscountTarget: int
{
    use HasLookupCode;

    case Clinic = 1;
    case Worker = 2;
}
