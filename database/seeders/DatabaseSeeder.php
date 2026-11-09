<?php

namespace Database\Seeders;

use App\Actions\Billing\UpdatePaymentStatus;
use App\Actions\Clinics\AddClinicMember;
use App\Actions\Clinics\AddSessionPatient;
use App\Actions\Clinics\CreateClinic;
use App\Actions\Clinics\SaveDailySession;
use App\Actions\Payroll\GeneratePayout;
use App\Enums\AttendanceStatus;
use App\Enums\ClinicRoleKey;
use App\Enums\DiscountTarget;
use App\Enums\DiscountType;
use App\Enums\PatientFormFieldType;
use App\Enums\PaymentStatus;
use App\Enums\ProgressRating;
use App\Enums\SessionStatus;
use App\Models\Clinic;
use App\Models\ClinicSession;
use App\Models\ClinicUser;
use App\Models\LineItem;
use App\Models\Patient;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;
use Illuminate\Support\Collection;

class DatabaseSeeder extends Seeder
{
    use WithoutModelEvents;

    /**
     * Seed the application's database with a super admin and one demo clinic.
     * Every account's password is "password".
     */
    public function run(
        CreateClinic $createClinic,
        AddClinicMember $addClinicMember,
        AddSessionPatient $addSessionPatient,
        SaveDailySession $saveDailySession,
    ): void {
        User::factory()->superAdmin()->create([
            'name' => 'Super Admin',
            'email' => 'superadmin@example.com',
        ]);

        $clinic = $createClinic->handle(
            ['name' => 'Demo Clinic', 'address' => '123 Example Street'],
            ['name' => 'Clinic Admin', 'email' => 'admin@example.com', 'password' => 'password'],
        );

        $workerRole = $clinic->clinicRoles()->where('key', ClinicRoleKey::Worker)->firstOrFail();

        $worker = $addClinicMember->handle(
            $clinic,
            ['name' => 'Clinic Worker', 'email' => 'worker@example.com', 'password' => 'password'],
            [$workerRole->id],
        );

        $patients = Patient::factory()->count(10)->for($clinic)->create();

        [, $therapy, $assessment] = LineItem::factory()->for($clinic)->createMany([
            ['item_code' => 'CONSULT', 'name' => 'Consultation', 'price' => 800],
            ['item_code' => 'THERAPY', 'name' => 'Therapy session', 'price' => 1500],
            ['item_code' => 'ASSESS', 'name' => 'Assessment', 'price' => 2500],
        ]);

        ClinicSession::factory()
            ->count(3)
            ->sequence(fn ($sequence) => [
                'starts_at' => now()->addDays($sequence->index + 1)->setTime(9, 0),
                'ends_at' => now()->addDays($sequence->index + 1)->setTime(10, 0),
            ])
            ->create(['clinic_user_id' => $worker->id, 'clinic_id' => $clinic->id])
            ->each(fn (ClinicSession $session) => $patients->random(2)->each(
                fn (Patient $patient) => $addSessionPatient->handle($session, $patient->id),
            ));

        // A daily session with six enrolled patients. The first four have a
        // usual 30-minute slot from 13:00 (one has an assessment instead of
        // therapy); the last two are left for the clinic to plot each day.
        $saveDailySession->create($clinic, [
            'clinic_user_id' => $worker->id,
            'name' => 'Daily physio',
            'line_item_id' => $therapy->id,
            'unit_price' => $therapy->price,
            'start_time' => '13:00',
            'end_time' => '17:00',
            'weekdays' => [1, 2, 3, 4, 5, 6],
            'starts_on' => now($clinic->timezone ?? 'UTC')->toDateString(),
        ], array_values($patients->take(6)->values()->map(fn (Patient $patient, int $index): array => [
            'patient_id' => $patient->id,
            'line_item_id' => $index === 1 ? $assessment->id : null,
            'start_time' => $index < 4 ? sprintf('%02d:%02d', 13 + intdiv($index * 30, 60), ($index * 30) % 60) : null,
            'duration_minutes' => $index < 4 ? 30 : null,
        ])->all()));

        $this->seedPayroll($clinic, $worker, $patients, $therapy);
        $this->seedPatientModule($clinic, $worker, $patients);
    }

    /**
     * Patient module demo data: two fields added to the clinic's form, the
     * answers to them, a patient whose progress notes show them getting
     * better, and an intake link waiting to be filled in.
     *
     * @param  Collection<int, Patient>  $patients
     */
    private function seedPatientModule(Clinic $clinic, ClinicUser $worker, Collection $patients): void
    {
        $allergies = $clinic->patientFormFields()->create([
            'key' => 'allergies',
            'label' => 'Allergies',
            'patient_form_field_type_id' => PatientFormFieldType::Textarea,
            'help_text' => 'Medicines, food, anything else.',
            'section' => 'Medical',
            'position' => 20,
        ]);

        $bloodType = $clinic->patientFormFields()->create([
            'key' => 'blood_type',
            'label' => 'Blood type',
            'patient_form_field_type_id' => PatientFormFieldType::Select,
            'options' => ['A+', 'A-', 'B+', 'B-', 'AB+', 'AB-', 'O+', 'O-'],
            'section' => 'Medical',
            'position' => 21,
        ]);

        $patients->take(5)->each(function (Patient $patient, int $index) use ($allergies, $bloodType): void {
            $patient->patientFieldValues()->create([
                'patient_form_field_id' => $allergies->id,
                'value' => ['None known', 'Penicillin', 'Shellfish', 'None known', 'Pollen'][$index],
            ]);
            $patient->patientFieldValues()->create([
                'patient_form_field_id' => $bloodType->id,
                'value' => ['O+', 'A-', 'B+', 'O-', 'AB+'][$index],
            ]);
        });

        // One patient with a run of notes that shows real improvement.
        $improving = $patients->first();
        $today = CarbonImmutable::now($clinic->timezone ?? 'UTC')->startOfDay();

        foreach ([
            [42, ProgressRating::Baseline, 'First assessment', 'Sharp pain on stairs, limited flexion.', 8, 85],
            [35, ProgressRating::Unchanged, 'No change yet', 'Still guarding the joint.', 8, 88],
            [28, ProgressRating::Improved, 'Climbing stairs again', 'Pain only at the end of range.', 6, 95],
            [14, ProgressRating::Improved, 'Back to light jogging', 'Confident on uneven ground.', 4, 108],
            [3, ProgressRating::Improved, 'Nearly discharged', 'One more review to confirm.', 2, 118],
        ] as [$daysAgo, $rating, $summary, $body, $pain, $flexion]) {
            $note = $clinic->patientProgressNotes()->create([
                'patient_id' => $improving->id,
                'clinic_user_id' => $worker->id,
                'recorded_on' => $today->subDays($daysAgo)->toDateString(),
                'progress_rating_id' => $rating,
                'summary' => $summary,
                'body' => $body,
                'plan' => 'Continue the home programme twice a day.',
            ]);

            $note->metrics()->createMany([
                ['name' => 'Pain', 'value' => $pain, 'unit' => '/10'],
                ['name' => 'Knee flexion', 'value' => $flexion, 'unit' => 'deg'],
            ]);
        }

        // And one who is going the other way, so the page is not all good news.
        $struggling = $patients->get(1);

        foreach ([
            [30, ProgressRating::Baseline, 'First assessment', 7],
            [10, ProgressRating::Declined, 'Flare-up after moving house', 9],
        ] as [$daysAgo, $rating, $summary, $pain]) {
            $clinic->patientProgressNotes()->create([
                'patient_id' => $struggling->id,
                'clinic_user_id' => $worker->id,
                'recorded_on' => $today->subDays($daysAgo)->toDateString(),
                'progress_rating_id' => $rating,
                'summary' => $summary,
            ])->metrics()->create(['name' => 'Pain', 'value' => $pain, 'unit' => '/10']);
        }

        $invitation = $clinic->patientFormInvitations()->make([
            'name' => 'New patient',
            'email' => 'newpatient@example.com',
        ]);
        $invitation->created_by_clinic_user_id = $worker->id;
        $token = $invitation->issueToken();
        $invitation->save();

        $this->command?->info('  Patient intake link: '.route('patient-form.show', $token));
    }

    /**
     * Payroll demo data: the clinic's split, a few discounts, two weeks of
     * attendance, finished sessions to be paid for, and one payout already
     * generated. One of its sessions is still unpaid by the patient, so the
     * Receivables page has a real advance in it.
     *
     * @param  Collection<int, Patient>  $patients
     */
    private function seedPayroll(Clinic $clinic, ClinicUser $worker, Collection $patients, LineItem $therapy): void
    {
        $clinic->payoutSettings()->update(['worker_share_percentage' => '60.00']);
        // One member negotiated their own cut.
        $worker->update(['payout_share_percentage' => '65.00']);

        $seniorDiscount = $clinic->discounts()->create([
            'name' => 'Senior citizen',
            'discount_type_id' => DiscountType::Percentage,
            'discount_target_id' => DiscountTarget::Clinic,
            'value' => '20.00',
        ]);
        $clinic->discounts()->create([
            'name' => 'Staff referral',
            'discount_type_id' => DiscountType::Fixed,
            'discount_target_id' => DiscountTarget::Worker,
            'value' => '200.00',
        ]);
        $clinic->discounts()->create([
            'clinic_user_id' => $worker->id,
            'name' => 'My long-term patients',
            'discount_type_id' => DiscountType::Percentage,
            'discount_target_id' => DiscountTarget::Worker,
            'value' => '10.00',
        ]);

        $today = CarbonImmutable::now($clinic->timezone)->startOfDay();

        foreach ($clinic->clinicUsers()->get() as $member) {
            foreach (range(1, 14) as $daysAgo) {
                $day = $today->subDays($daysAgo);

                if ($day->isSunday()) {
                    continue;
                }

                $clinic->attendances()->create([
                    'clinic_user_id' => $member->id,
                    'work_date' => $day->toDateString(),
                    'attendance_status_id' => match ($daysAgo) {
                        4 => AttendanceStatus::Late,
                        9 => AttendanceStatus::Absent,
                        default => AttendanceStatus::Present,
                    },
                    'checked_in_at' => $day->setTime($daysAgo === 4 ? 9 : 8, 30),
                    'checked_out_at' => $day->setTime(17, 0),
                    'recorded_by_clinic_user_id' => $member->id,
                ]);
            }
        }

        // Finished sessions over the past fortnight, two of them discounted.
        $finished = collect(range(1, 6))->map(function (int $daysAgo) use ($clinic, $worker, $patients, $therapy, $seniorDiscount, $today) {
            $starts = $today->subDays($daysAgo)->setTime(10, 0)->utc();

            $session = $clinic->clinicSessions()->create([
                'clinic_user_id' => $worker->id,
                'name' => 'Therapy session',
                'starts_at' => $starts,
                'ends_at' => $starts->addHour(),
                'status_id' => SessionStatus::Done,
            ]);

            $sessionMember = app(AddSessionPatient::class)->handle($session, $patients->get($daysAgo % $patients->count())->id);
            $discount = $daysAgo % 3 === 0 ? $seniorDiscount : null;

            $sessionMember->sessionLineItems()->create([
                'line_item_id' => $therapy->id,
                'quantity' => 1,
                'unit_price' => $therapy->price,
                'discount_id' => $discount?->id,
                'discount_amount' => $discount?->deductionFrom($therapy->price) ?? '0.00',
                'discount_target_id' => $discount?->discount_target_id,
            ]);

            return $sessionMember;
        });

        // Everyone but the two most recent patients has paid.
        $finished->slice(2)->each(fn ($sessionMember) => app(UpdatePaymentStatus::class)->handle(
            $sessionMember->payment,
            PaymentStatus::Done,
            $worker,
        ));

        app(GeneratePayout::class)->handle(
            clinic: $clinic,
            member: $worker,
            periodStart: $today->subDays(14),
            periodEnd: $today->subDay(),
            generatedBy: $clinic->clinicUsers()->whereKeyNot($worker->id)->firstOrFail(),
            notes: 'Fortnightly payout.',
        );
    }
}
