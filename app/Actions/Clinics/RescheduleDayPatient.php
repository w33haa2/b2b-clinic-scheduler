<?php

namespace App\Actions\Clinics;

use App\Enums\SessionStatus;
use App\Models\ClinicSession;
use App\Models\ClinicSessionMember;
use App\Models\ClinicUser;
use App\Support\ActivityLogger;
use App\Support\HolderAvailability;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Moves one patient off a day of a daily session and into a makeup session
 * on another date.
 *
 * The makeup is a session of its own rather than another place on an
 * existing day: a patient can be on a day only once
 * (`clinic_session_members` is unique on the session and the patient), and
 * the date they move to is usually one the daily session already runs, where
 * they are already enrolled.
 *
 * What they owe moves with them. The billed items and the payment are
 * reassigned, not copied, so a patient who prepaid keeps their payment and
 * nobody is charged twice. The row on the missed day stays behind, pointing
 * at the makeup, so the day still shows that the patient was expected.
 */
class RescheduleDayPatient
{
    public function __construct(private HolderAvailability $availability) {}

    /**
     * @param  array{starts_at: CarbonImmutable, ends_at: CarbonImmutable, clinic_user_id?: int|null, reason?: string|null}  $makeup
     */
    public function handle(ClinicSessionMember $sessionMember, array $makeup): ClinicSessionMember
    {
        $day = $sessionMember->clinicSession;
        $startsAt = $makeup['starts_at'];
        $endsAt = $makeup['ends_at'];

        $this->assertMovable($sessionMember, $day);

        $holder = $this->resolveHolder($day, $makeup['clinic_user_id'] ?? null);

        $this->assertFree($holder, $startsAt, $endsAt, $sessionMember);

        return DB::transaction(function () use ($sessionMember, $day, $holder, $startsAt, $endsAt, $makeup): ClinicSessionMember {
            $session = $day->clinic->clinicSessions()->create([
                'clinic_user_id' => $holder,
                'name' => $this->nameFor($day),
                'starts_at' => $startsAt,
                'ends_at' => $endsAt,
                'status_id' => $startsAt->isFuture() ? SessionStatus::Pending : SessionStatus::Ongoing,
            ]);

            // Created quietly: the move is logged once, against the patient,
            // rather than as an unrelated session appearing out of nowhere.
            $makeupMember = ActivityLogger::quietly(fn (): ClinicSessionMember => $session->sessionMembers()->create([
                'patient_id' => $sessionMember->patient_id,
                'starts_at' => $startsAt,
                'ends_at' => $endsAt,
                'customised' => true,
            ]));

            $sessionMember->sessionLineItems()->update(['clinic_session_member_id' => $makeupMember->id]);

            $sessionMember->payment()->update([
                'clinic_session_member_id' => $makeupMember->id,
                'clinic_session_id' => $session->id,
            ]);

            // The patient is no longer in the room on the missed day, so they
            // free the slot they held; the record of them stays.
            $sessionMember->forceFill([
                'starts_at' => null,
                'ends_at' => null,
                'customised' => true,
                'moved_to_clinic_session_member_id' => $makeupMember->id,
                'moved_at' => now(),
                'moved_reason' => $makeup['reason'] ?? null,
            ])->save();

            app(ActivityLogger::class)->log(
                'session_patient.rescheduled',
                $sessionMember,
                $day->clinic_id,
                $sessionMember->activityLabel(),
                context: [
                    'from' => $day->activityLabel(),
                    'to' => $session->activityLabel(),
                    'reason' => $makeup['reason'] ?? null,
                ],
            );

            return $makeupMember->setRelation('clinicSession', $session);
        });
    }

    /**
     * A makeup can be arranged before, during or after the day: a patient
     * often says they cannot come at the last minute, and just as often
     * after they have already missed it. Only a day that never happened for
     * anyone, and a patient already moved, are refused.
     */
    private function assertMovable(ClinicSessionMember $sessionMember, ?ClinicSession $day): void
    {
        if ($day?->daily_session_id === null) {
            throw ValidationException::withMessages([
                'session_member' => __('Only a patient on a day of a daily session can be given a makeup.'),
            ]);
        }

        if ($sessionMember->wasMoved()) {
            throw ValidationException::withMessages([
                'session_member' => __('This patient already has a makeup session for that day.'),
            ]);
        }

        if ($day->status_id === SessionStatus::Cancelled) {
            throw ValidationException::withMessages([
                'session_member' => __('That day was cancelled, so there is nothing to move.'),
            ]);
        }

        $invoiced = $sessionMember->sessionLineItems()->whereHas('invoiceItem')->exists();

        if ($invoiced) {
            throw ValidationException::withMessages([
                'session_member' => __('What this patient owes for that day is already on an invoice. Remove it from the invoice first.'),
            ]);
        }
    }

    /**
     * The makeup is held by the same member unless another is named, and
     * that member has to belong to the same clinic.
     */
    private function resolveHolder(ClinicSession $day, ?int $clinicUserId): int
    {
        if ($clinicUserId === null || $clinicUserId === $day->clinic_user_id) {
            return $day->clinic_user_id;
        }

        $belongs = ClinicUser::query()
            ->whereKey($clinicUserId)
            ->where('clinic_id', $day->clinic_id)
            ->exists();

        if (! $belongs) {
            throw ValidationException::withMessages([
                'clinic_user_id' => __('That staff member is not part of this clinic.'),
            ]);
        }

        return $clinicUserId;
    }

    private function assertFree(int $clinicUserId, CarbonImmutable $startsAt, CarbonImmutable $endsAt, ClinicSessionMember $sessionMember): void
    {
        if ($this->availability->isBusy($clinicUserId, $startsAt, $endsAt, $sessionMember->id)) {
            throw ValidationException::withMessages([
                'starts_at' => __('That time is already taken for the staff member holding the makeup.'),
            ]);
        }
    }

    private function nameFor(ClinicSession $day): string
    {
        // assertMovable() has already established that this is a day of a
        // daily session, so the relation is there.
        return __(':name (makeup)', ['name' => $day->name ?? $day->dailySession->name]);
    }
}
