<?php

namespace Database\Factories;

use App\Enums\AttendanceStatus;
use App\Models\Attendance;
use App\Models\ClinicUser;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Attendance>
 */
class AttendanceFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'clinic_user_id' => ClinicUser::factory(),
            'clinic_id' => fn (array $attributes) => ClinicUser::query()->findOrFail((int) $attributes['clinic_user_id'])->clinic_id,
            'work_date' => fake()->dateTimeBetween('-30 days', 'now')->format('Y-m-d'),
            'attendance_status_id' => AttendanceStatus::Present,
        ];
    }

    /**
     * Record the day with the given status.
     */
    public function status(AttendanceStatus $status): static
    {
        return $this->state(['attendance_status_id' => $status]);
    }

    /**
     * Record the day for the given member, on the given date.
     */
    public function on(ClinicUser $member, string $workDate): static
    {
        return $this->state([
            'clinic_user_id' => $member->id,
            'clinic_id' => $member->clinic_id,
            'work_date' => $workDate,
        ]);
    }
}
