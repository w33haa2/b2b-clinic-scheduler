<?php

namespace App\Actions\Clinics;

use App\Models\Clinic;
use App\Models\ClinicSession;
use App\Models\ClinicSessionMember;
use App\Models\DailySession;
use App\Models\DailySessionPatient;
use App\Support\ActivityLogger;
use Brick\Math\BigDecimal;
use Illuminate\Support\Facades\DB;

class SaveDailySession
{
    public function __construct(
        private ScheduleDailySessionDays $scheduleDays,
        private EnrollDailySessionPatient $enroll,
        private UpdateDailySessionPatientPlan $updatePlan,
        private RemoveDailySessionPatient $removePatient,
    ) {}

    /**
     * Create a daily session, enrol its first patients with their plans and
     * schedule the upcoming days (each patient billed their service and put
     * into their usual slot where the staff member is free).
     *
     * @param  array<string, mixed>  $attributes
     * @param  list<int|array<string, mixed>>  $patients  Patient IDs, or plans:
     *                                                    patient_id, line_item_id?, unit_price?, start_time?, duration_minutes?
     */
    public function create(Clinic $clinic, array $attributes, array $patients = []): DailySession
    {
        return DB::transaction(function () use ($clinic, $attributes, $patients): DailySession {
            $dailySession = $clinic->dailySessions()->create($attributes);
            $dailySession->setRelation('clinic', $clinic);

            foreach ($patients as $patient) {
                $dailySession->dailySessionPatients()->create($dailySession->planAttributes(is_int($patient) ? ['patient_id' => $patient] : $patient));
            }

            $this->scheduleDays->handle($dailySession);

            return $dailySession;
        });
    }

    /**
     * Update a daily session and re-plan the days that have not started:
     * they take the new name, holder and hours; days no longer on the
     * schedule are removed (refused if someone prepaid one); each patient is
     * put back into their usual slot, except on days changed by hand, where
     * the slot is only cleared if it no longer fits. A new default service
     * only applies to patients enrolled afterwards.
     *
     * With `$patients` (the full list of plans), enrolments are synced too:
     * new patients are enrolled, changed plans are applied to upcoming days
     * (except days changed by hand) and patients left out are removed from
     * upcoming days. All or nothing: if one patient cannot be removed (they
     * prepaid a day), nothing is saved.
     *
     * @param  array<string, mixed>  $attributes
     * @param  list<array<string, mixed>>|null  $patients
     */
    public function update(DailySession $dailySession, array $attributes, ?array $patients = null): DailySession
    {
        return DB::transaction(function () use ($dailySession, $attributes, $patients): DailySession {
            $dailySession->fill($attributes);
            $holderChanged = $dailySession->isDirty('clinic_user_id');
            $dailySession->save();

            $upcoming = $this->scheduleDays->upcomingDays($dailySession);
            [$keep, $drop] = $upcoming->partition(fn (ClinicSession $day): bool => $dailySession->runsOn($day->occurs_on));

            $this->scheduleDays->remove($drop->values());

            $plans = $this->scheduleDays->plans($dailySession)->keyBy('patient_id');

            ActivityLogger::quietly(fn () => $keep->each(function (ClinicSession $day) use ($dailySession, $holderChanged, $plans): void {
                [$startsAt, $endsAt] = $dailySession->windowOn($day->occurs_on);

                $day->update([
                    'name' => $dailySession->name,
                    'clinic_user_id' => $dailySession->clinic_user_id,
                    'starts_at' => $startsAt,
                    'ends_at' => $endsAt,
                ]);
                $day->setRelation('dailySession', $dailySession);

                $day->sessionMembers()->orderBy('id')->get()->each(function (ClinicSessionMember $sessionMember) use ($day, $plans, $holderChanged, $startsAt, $endsAt): void {
                    $plan = $plans->get($sessionMember->patient_id);

                    if ($plan !== null && ! $sessionMember->customised) {
                        $this->scheduleDays->plotFromPlan($sessionMember, $plan, $day);

                        return;
                    }

                    $stillFits = $sessionMember->starts_at !== null && ! $holderChanged
                        && $sessionMember->starts_at->greaterThanOrEqualTo($startsAt)
                        && $sessionMember->ends_at->lessThanOrEqualTo($endsAt);

                    if ($sessionMember->starts_at !== null && ! $stillFits) {
                        $sessionMember->update(['starts_at' => null, 'ends_at' => null]);
                    }
                });
            }));

            if ($patients !== null) {
                $this->syncPatients($dailySession, $patients);
            }

            $this->scheduleDays->handle($dailySession);

            return $dailySession;
        });
    }

    /**
     * Make the enrolments match the given plans. Removals go first so their
     * usual slots are free for the others.
     *
     * @param  list<array<string, mixed>>  $patients
     */
    private function syncPatients(DailySession $dailySession, array $patients): void
    {
        $wanted = collect($patients)->keyBy(fn (array $plan): int => (int) $plan['patient_id']);
        $current = $dailySession->dailySessionPatients()->with(['patient', 'dailySession'])->get()->keyBy('patient_id');

        $current->diffKeys($wanted)->each(fn (DailySessionPatient $enrollment) => $this->removePatient->handle($enrollment));

        foreach ($wanted as $patientId => $plan) {
            $enrollment = $current->get($patientId);

            if ($enrollment === null) {
                $this->enroll->handle($dailySession, $plan);

                continue;
            }

            $changes = $this->changedPlan($enrollment, $dailySession->planAttributes($plan));

            if ($changes !== []) {
                $this->updatePlan->handle($enrollment, $changes);
            }
        }
    }

    /**
     * The plan attributes that differ from the enrolment's current plan.
     *
     * @param  array<string, mixed>  $plan
     * @return array<string, mixed>
     */
    private function changedPlan(DailySessionPatient $enrollment, array $plan): array
    {
        $current = [
            'line_item_id' => $enrollment->line_item_id,
            'unit_price' => (string) BigDecimal::of($enrollment->unit_price)->toScale(2),
            'start_time' => $enrollment->start_time !== null ? substr($enrollment->start_time, 0, 5) : null,
            'duration_minutes' => $enrollment->duration_minutes,
        ];
        $next = [
            'line_item_id' => (int) $plan['line_item_id'],
            'unit_price' => (string) BigDecimal::of((string) $plan['unit_price'])->toScale(2),
            'start_time' => $plan['start_time'] !== null ? substr((string) $plan['start_time'], 0, 5) : null,
            'duration_minutes' => $plan['duration_minutes'] !== null ? (int) $plan['duration_minutes'] : null,
        ];

        return array_diff_assoc($next, $current) === [] && array_diff_assoc($current, $next) === []
            ? []
            : $next;
    }
}
