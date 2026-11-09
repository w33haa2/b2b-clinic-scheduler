<?php

namespace Database\Factories;

use App\Enums\DiscountTarget;
use App\Enums\DiscountType;
use App\Models\Clinic;
use App\Models\ClinicUser;
use App\Models\Discount;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Discount>
 */
class DiscountFactory extends Factory
{
    /**
     * Define the model's default state. Clinic-wide and taken out of the
     * clinic's cut, which is the usual case.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'clinic_id' => Clinic::factory(),
            'clinic_user_id' => null,
            'name' => fake()->randomElement(['Senior citizen', 'Student rate', 'Staff family', 'Loyalty']),
            'discount_type_id' => DiscountType::Percentage,
            'discount_target_id' => DiscountTarget::Clinic,
            'value' => fake()->randomElement(['5.00', '10.00', '20.00']),
            'is_active' => true,
        ];
    }

    /**
     * Make it a personal discount only the given member can see.
     */
    public function personal(ClinicUser $member): static
    {
        return $this->state(['clinic_id' => $member->clinic_id, 'clinic_user_id' => $member->id]);
    }

    /**
     * Take a flat amount off instead of a percentage.
     */
    public function fixed(string $value): static
    {
        return $this->state(['discount_type_id' => DiscountType::Fixed, 'value' => $value]);
    }

    /**
     * Take the discount out of the given side of the split.
     */
    public function target(DiscountTarget $target): static
    {
        return $this->state(['discount_target_id' => $target]);
    }
}
