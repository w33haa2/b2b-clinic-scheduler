<?php

namespace Database\Factories;

use App\Enums\ProgressRating;
use App\Models\ClinicUser;
use App\Models\Patient;
use App\Models\PatientProgressNote;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<PatientProgressNote>
 */
class PatientProgressNoteFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'patient_id' => Patient::factory(),
            'clinic_id' => fn (array $attributes) => Patient::query()->findOrFail((int) $attributes['patient_id'])->clinic_id,
            'recorded_on' => fake()->dateTimeBetween('-60 days', 'now')->format('Y-m-d'),
            'progress_rating_id' => ProgressRating::Baseline,
            'summary' => fake()->randomElement([
                'First assessment',
                'Range of motion improving',
                'Pain unchanged since last visit',
                'Flare-up after the weekend',
                'Discharged, goals met',
            ]),
        ];
    }

    /**
     * Rate the note.
     */
    public function rated(ProgressRating $rating): static
    {
        return $this->state(['progress_rating_id' => $rating]);
    }

    /**
     * Write it for the given patient on the given day.
     */
    public function on(Patient $patient, string $recordedOn): static
    {
        return $this->state([
            'patient_id' => $patient->id,
            'clinic_id' => $patient->clinic_id,
            'recorded_on' => $recordedOn,
        ]);
    }

    /**
     * Attribute it to a member of staff.
     */
    public function by(ClinicUser $member): static
    {
        return $this->state(['clinic_user_id' => $member->id, 'clinic_id' => $member->clinic_id]);
    }
}
