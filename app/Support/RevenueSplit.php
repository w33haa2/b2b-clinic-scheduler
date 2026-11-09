<?php

namespace App\Support;

use App\Enums\DiscountTarget;
use App\Models\SessionLineItem;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;

/**
 * How one billed item is split between the member who facilitated the
 * session and the clinic.
 *
 * ```
 * gross       = quantity × unit price
 * net         = gross − discount          what the patient owes
 * worker base = gross × share%
 * clinic base = gross − worker base
 * ```
 *
 * The discount comes out of the side it targets. If it is bigger than that
 * side, the rest spills onto the other one, so neither side can go negative
 * and `worker + clinic` always equals `net`.
 */
final readonly class RevenueSplit
{
    public function __construct(
        public string $sharePercentage,
        public string $gross,
        public string $discount,
        public string $worker,
        public string $clinic,
    ) {}

    /**
     * Split a billed item using the given share of the gross.
     */
    public static function forItem(SessionLineItem $sessionLineItem, string $sharePercentage): self
    {
        return self::of(
            $sessionLineItem->grossAmount(),
            $sessionLineItem->discount_amount,
            $sessionLineItem->discount_target_id ?? DiscountTarget::Clinic,
            $sharePercentage,
        );
    }

    /**
     * Split a gross amount, taking the discount out of the given side.
     */
    public static function of(string $gross, string $discount, DiscountTarget $target, string $sharePercentage): self
    {
        $grossAmount = BigDecimal::of($gross)->toScale(2, RoundingMode::HalfUp);
        $discountAmount = self::clamp(BigDecimal::of($discount)->toScale(2, RoundingMode::HalfUp), $grossAmount);

        $workerBase = $grossAmount->multipliedBy($sharePercentage)->dividedBy(100, 2, RoundingMode::HalfUp);
        $clinicBase = $grossAmount->minus($workerBase);

        [$fromWorker, $fromClinic] = $target === DiscountTarget::Worker
            ? self::take($discountAmount, $workerBase)
            : array_reverse(self::take($discountAmount, $clinicBase));

        return new self(
            sharePercentage: (string) BigDecimal::of($sharePercentage)->toScale(2, RoundingMode::HalfUp),
            gross: (string) $grossAmount,
            discount: (string) $discountAmount,
            worker: (string) $workerBase->minus($fromWorker),
            clinic: (string) $clinicBase->minus($fromClinic),
        );
    }

    /**
     * What the patient owes for the item: the two sides added back together.
     */
    public function net(): string
    {
        return (string) BigDecimal::of($this->worker)->plus($this->clinic)->toScale(2);
    }

    /**
     * Take as much of the discount as the targeted side can bear, and return
     * what it bore together with the remainder for the other side.
     *
     * @return array{0: BigDecimal, 1: BigDecimal}
     */
    private static function take(BigDecimal $discount, BigDecimal $side): array
    {
        $taken = self::clamp($discount, $side);

        return [$taken, $discount->minus($taken)];
    }

    /**
     * The amount, held between zero and the ceiling. BigDecimal::min() and
     * max() are static in brick/math, so they cannot be chained here.
     */
    private static function clamp(BigDecimal $amount, BigDecimal $ceiling): BigDecimal
    {
        if ($amount->isNegative()) {
            return BigDecimal::zero()->toScale(2);
        }

        if ($ceiling->isNegative()) {
            return BigDecimal::zero()->toScale(2);
        }

        return $amount->isGreaterThan($ceiling) ? $ceiling : $amount;
    }
}
