<?php

namespace App\Enums;

use App\Enums\Concerns\HasLookupCode;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;

/**
 * Rows of the discount_types lookup table. Case values are the row IDs.
 */
enum DiscountType: int
{
    use HasLookupCode;

    case Percentage = 1;
    case Fixed = 2;

    /**
     * What this discount takes off a gross amount. Never more than the amount
     * itself, so a billed item can never go negative.
     */
    public function deductionFrom(string $gross, string $value): string
    {
        $grossAmount = BigDecimal::of($gross)->toScale(2, RoundingMode::HalfUp);

        $deduction = match ($this) {
            self::Percentage => $grossAmount->multipliedBy($value)->dividedBy(100, 2, RoundingMode::HalfUp),
            self::Fixed => BigDecimal::of($value)->toScale(2, RoundingMode::HalfUp),
        };

        if ($deduction->isNegative()) {
            return (string) BigDecimal::zero()->toScale(2);
        }

        return (string) ($deduction->isGreaterThan($grossAmount) ? $grossAmount : $deduction);
    }
}
