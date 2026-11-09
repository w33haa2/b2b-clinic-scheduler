<?php

namespace App\Enums;

use App\Enums\Concerns\HasLookupCode;

/**
 * Rows of the transaction_types lookup table. Case values are the row IDs.
 */
enum TransactionType: int
{
    use HasLookupCode;

    case Inbound = 1;
    case Reversed = 2;
}
