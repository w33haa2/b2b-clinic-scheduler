<?php

use App\Actions\Billing\UpdatePaymentStatus;
use App\Actions\Clinics\SaveDailySession;
use App\Enums\PaymentStatus;
use App\Enums\SessionStatus;
use App\Models\ClinicSession;
use App\Models\ClinicSessionMember;
use App\Models\ClinicUser;
use App\Models\LineItem;
use App\Models\Patient;
use Laravel\Sanctum\Sanctum;

/*
 * A patient who misses a day of a daily session gets a makeup on another
 * date. The makeup is a session of its own, because the date is usually one
 * the daily session already runs and a patient can only be on a day once.
 *
 * The thing that must not break is the money: what the patient owes moves
 * with them, so nobody is billed twice and a payment already made follows.
 */

beforeEach(function () {
    // Monday 2 November 2026, 06:00 UTC.
    $this->travelTo(now()->setDate(2026, 11, 2)->setTime(6, 0));

    $this->worker = ClinicUser::factory()->worker()->create();
    $patient = Patient::factory()->for($this->worker->clinic)->create();

    $dailySession = app(SaveDailySession::class)->create($this->worker->clinic, [
        'clinic_user_id' => $this->worker->id,
        'name' => 'Daily physio',
        'line_item_id' => LineItem::factory()->for($this->worker->clinic)->create()->id,
        'unit_price' => '500.00',
        'start_time' => '08:00',
        'end_time' => '12:00',
        'weekdays' => [1],
        'starts_on' => '2026-11-02',
    ], [$patient->id]);

    $this->day = $dailySession->clinicSessions()->whereDate('occurs_on', '2026-11-02')->sole();
    $this->member = $this->day->sessionMembers()->sole();

    Sanctum::actingAs($this->worker->user);
});

function rescheduleRoute(ClinicSession $day, ClinicSessionMember $sessionMember): string
{
    return route('clinic.sessions.members.reschedule.store', [$day->clinic_id, $day, $sessionMember]);
}

test('a makeup moves the patient, their bill and their payment to a new session', function () {
    $lineItemId = $this->member->sessionLineItems()->value('id');
    $paymentId = $this->member->payment->id;

    // Wednesday, which the daily session does not run on.
    $response = $this->postJson(rescheduleRoute($this->day, $this->member), [
        'starts_at' => '2026-11-04T09:00:00Z',
        'ends_at' => '2026-11-04T10:00:00Z',
        'reason' => 'Patient was away',
    ])->assertCreated()->assertJsonPath('data.starts_at', '2026-11-04T09:00:00.000000Z');

    $makeupMemberId = $response->json('data.id');

    // Billed once, on the makeup rather than the day that was missed.
    $this->assertDatabaseHas('session_line_items', ['id' => $lineItemId, 'clinic_session_member_id' => $makeupMemberId]);
    $this->assertDatabaseHas('payments', ['id' => $paymentId, 'clinic_session_member_id' => $makeupMemberId]);
    expect($this->member->sessionLineItems()->count())->toBe(0)
        ->and(ClinicSessionMember::query()->whereKey($makeupMemberId)->value('clinic_session_id'))
        ->not->toBe($this->day->id);

    // The missed day keeps its record of them, pointing at where they went.
    $this->member->refresh();
    expect($this->member->moved_to_clinic_session_member_id)->toBe($makeupMemberId)
        ->and($this->member->moved_reason)->toBe('Patient was away')
        ->and($this->member->starts_at)->toBeNull();
});

test('the makeup stays with the same staff member unless another is named', function () {
    $this->postJson(rescheduleRoute($this->day, $this->member), [
        'starts_at' => '2026-11-04T09:00:00Z',
        'ends_at' => '2026-11-04T10:00:00Z',
    ])->assertCreated();

    $makeup = ClinicSession::query()->whereNull('daily_session_id')->sole();

    expect($makeup->clinic_user_id)->toBe($this->worker->id)
        ->and($makeup->name)->toBe('Daily physio (makeup)');
});

test('a makeup can be handed to a different member of the same clinic', function () {
    $colleague = ClinicUser::factory()->worker()->create(['clinic_id' => $this->worker->clinic_id]);

    $this->postJson(rescheduleRoute($this->day, $this->member), [
        'starts_at' => '2026-11-04T09:00:00Z',
        'ends_at' => '2026-11-04T10:00:00Z',
        'clinic_user_id' => $colleague->id,
    ])->assertCreated();

    expect(ClinicSession::query()->whereNull('daily_session_id')->value('clinic_user_id'))->toBe($colleague->id);
});

test('a makeup cannot be handed to someone from another clinic', function () {
    $outsider = ClinicUser::factory()->worker()->create();

    $this->postJson(rescheduleRoute($this->day, $this->member), [
        'starts_at' => '2026-11-04T09:00:00Z',
        'ends_at' => '2026-11-04T10:00:00Z',
        'clinic_user_id' => $outsider->id,
    ])->assertJsonValidationErrors('clinic_user_id');
});

test('a patient who already paid keeps their payment on the makeup', function () {
    app(UpdatePaymentStatus::class)->handle($this->member->payment, PaymentStatus::Done, $this->worker);

    $response = $this->postJson(rescheduleRoute($this->day, $this->member), [
        'starts_at' => '2026-11-04T09:00:00Z',
        'ends_at' => '2026-11-04T10:00:00Z',
    ])->assertCreated();

    $this->assertDatabaseHas('payments', [
        'id' => $this->member->payment->id,
        'clinic_session_member_id' => $response->json('data.id'),
        'payment_status_id' => PaymentStatus::Done->value,
    ]);
});

test('a makeup can be arranged after the day has finished', function () {
    // The patient simply did not turn up; the day ran and ended.
    $this->day->update(['status_id' => SessionStatus::Done]);
    $this->travelTo(now()->addDays(1));

    $this->postJson(rescheduleRoute($this->day, $this->member), [
        'starts_at' => '2026-11-04T09:00:00Z',
        'ends_at' => '2026-11-04T10:00:00Z',
    ])->assertCreated();
});

test('a makeup clashing with the holder is refused', function () {
    ClinicSession::factory()->create([
        'clinic_user_id' => $this->worker->id,
        'starts_at' => '2026-11-04 09:30:00',
        'ends_at' => '2026-11-04 10:30:00',
    ]);

    $this->postJson(rescheduleRoute($this->day, $this->member), [
        'starts_at' => '2026-11-04T09:00:00Z',
        'ends_at' => '2026-11-04T10:00:00Z',
    ])->assertJsonValidationErrors('starts_at');
});

test('a makeup outside the opening hours is refused', function () {
    $this->postJson(rescheduleRoute($this->day, $this->member), [
        'starts_at' => '2026-11-04T05:00:00Z',
        'ends_at' => '2026-11-04T06:00:00Z',
    ])->assertJsonValidationErrors('starts_at');
});

test('a patient cannot be given two makeups for the same day', function () {
    $this->postJson(rescheduleRoute($this->day, $this->member), [
        'starts_at' => '2026-11-04T09:00:00Z',
        'ends_at' => '2026-11-04T10:00:00Z',
    ])->assertCreated();

    $this->postJson(rescheduleRoute($this->day, $this->member), [
        'starts_at' => '2026-11-05T09:00:00Z',
        'ends_at' => '2026-11-05T10:00:00Z',
    ])->assertJsonValidationErrors('session_member');
});

test('a cancelled day has nothing to move', function () {
    $this->day->update(['status_id' => SessionStatus::Cancelled]);

    $this->postJson(rescheduleRoute($this->day, $this->member), [
        'starts_at' => '2026-11-04T09:00:00Z',
        'ends_at' => '2026-11-04T10:00:00Z',
    ])->assertJsonValidationErrors('session_member');
});

test('a patient of a one-off session has no daily plan to make up', function () {
    $session = ClinicSession::factory()->for($this->worker->clinic)->create([
        'clinic_user_id' => $this->worker->id,
        'starts_at' => '2026-11-03 09:00:00',
        'ends_at' => '2026-11-03 10:00:00',
    ]);
    $member = $session->sessionMembers()->create(['patient_id' => Patient::factory()->for($this->worker->clinic)->create()->id]);

    $this->postJson(rescheduleRoute($session, $member), [
        'starts_at' => '2026-11-04T09:00:00Z',
        'ends_at' => '2026-11-04T10:00:00Z',
    ])->assertJsonValidationErrors('session_member');
});

test('the move is written to the activity log', function () {
    $this->postJson(rescheduleRoute($this->day, $this->member), [
        'starts_at' => '2026-11-04T09:00:00Z',
        'ends_at' => '2026-11-04T10:00:00Z',
        'reason' => 'Flu',
    ])->assertCreated();

    $this->assertDatabaseHas('activity_logs', [
        'clinic_id' => $this->worker->clinic_id,
        'action' => 'session_patient.rescheduled',
    ]);
});
