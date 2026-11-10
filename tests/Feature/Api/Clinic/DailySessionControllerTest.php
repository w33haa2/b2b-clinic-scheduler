<?php

use App\Actions\Clinics\SaveDailySession;
use App\Enums\PaymentStatus;
use App\Enums\SessionStatus;
use App\Models\ActivityLog;
use App\Models\Clinic;
use App\Models\ClinicSession;
use App\Models\ClinicSessionMember;
use App\Models\ClinicUser;
use App\Models\DailySession;
use App\Models\LineItem;
use App\Models\Patient;
use App\Models\Payment;
use Laravel\Sanctum\Sanctum;

beforeEach(function () {
    // Monday 2 November 2026, 06:00 UTC.
    $this->travelTo(now()->setDate(2026, 11, 2)->setTime(6, 0));
});

/**
 * A daily session from today, 08:00–12:00 on weekdays, with the given patients.
 *
 * @param  array<string, mixed>  $attributes
 * @param  list<int>  $patientIds
 */
function createDailySession(ClinicUser $holder, array $patientIds = [], array $attributes = []): DailySession
{
    $lineItem = LineItem::factory()->for($holder->clinic)->create(['price' => '500.00']);

    return app(SaveDailySession::class)->create($holder->clinic, [
        'clinic_user_id' => $holder->id,
        'name' => 'Daily physio',
        'line_item_id' => $lineItem->id,
        'unit_price' => '500.00',
        'start_time' => '08:00',
        'end_time' => '12:00',
        'weekdays' => [1, 2, 3, 4, 5],
        'starts_on' => now()->toDateString(),
        ...$attributes,
    ], $patientIds);
}

test('creating a daily session schedules the next two weeks with a bill and an unpaid payment per patient', function () {
    $worker = ClinicUser::factory()->worker()->create();
    $patients = Patient::factory()->count(2)->for($worker->clinic)->create();
    $lineItem = LineItem::factory()->for($worker->clinic)->create(['price' => '750.00']);
    Sanctum::actingAs($worker->user);

    $this->postJson(route('clinic.daily-sessions.store', $worker->clinic_id), [
        'name' => 'Daily physio',
        'clinic_user_id' => $worker->id,
        'line_item_id' => $lineItem->id,
        'start_time' => '08:00',
        'end_time' => '12:00',
        'weekdays' => [5, 1, 2, 3, 4],
        'starts_on' => '2026-11-02',
        'patient_ids' => $patients->pluck('id')->all(),
    ])->assertCreated()
        ->assertJsonPath('data.unit_price', '750.00')
        ->assertJsonPath('data.weekdays', [1, 2, 3, 4, 5])
        ->assertJsonPath('data.patients_count', 2);

    // Mon 2 Nov – Mon 16 Nov: 11 weekdays.
    $days = ClinicSession::query()->whereNotNull('daily_session_id')->orderBy('starts_at')->get();

    expect($days)->toHaveCount(11)
        ->and($days->first()->starts_at->toIso8601String())->toBe('2026-11-02T08:00:00+00:00')
        ->and($days->first()->ends_at->toIso8601String())->toBe('2026-11-02T12:00:00+00:00')
        ->and($days->last()->occurs_on->toDateString())->toBe('2026-11-16');

    $this->assertDatabaseCount('clinic_session_members', 22);
    $this->assertDatabaseCount('payments', 22);
    $this->assertDatabaseHas('session_line_items', ['line_item_id' => $lineItem->id, 'unit_price' => '750.00']);
    expect(ActivityLog::query()->where('action', 'like', 'session_patient.%')->count())->toBe(0)
        ->and(ActivityLog::query()->where('action', 'daily_session.created')->count())->toBe(1)
        ->and(ActivityLog::query()->where('action', 'daily_session_patient.enrolled')->count())->toBe(2);
});

test('the daily window is in the clinic timezone', function () {
    $clinic = Clinic::factory()->create(['timezone' => 'Asia/Manila']);
    $worker = ClinicUser::factory()->worker()->create(['clinic_id' => $clinic->id]);

    $dailySession = createDailySession($worker, attributes: ['starts_on' => '2026-11-03', 'weekdays' => [2]]);

    expect($dailySession->clinicSessions()->orderBy('starts_at')->first()->starts_at->toIso8601String())
        ->toBe('2026-11-03T00:00:00+00:00');
});

test('a daily session rejects a window that ends before it starts', function () {
    $worker = ClinicUser::factory()->worker()->create();
    $lineItem = LineItem::factory()->for($worker->clinic)->create();
    Sanctum::actingAs($worker->user);

    $this->postJson(route('clinic.daily-sessions.store', $worker->clinic_id), [
        'name' => 'Late',
        'clinic_user_id' => $worker->id,
        'line_item_id' => $lineItem->id,
        'start_time' => '17:00',
        'end_time' => '09:00',
        'weekdays' => [1],
        'starts_on' => '2026-11-02',
    ])->assertUnprocessable()->assertJsonValidationErrors('end_time');
});

test('a daily session cannot use staff, services or patients of another clinic', function (string $field) {
    $worker = ClinicUser::factory()->worker()->create();
    $otherClinic = Clinic::factory()->create();
    $foreign = [
        'clinic_user_id' => fn () => ClinicUser::factory()->worker()->create(['clinic_id' => $otherClinic->id])->id,
        'line_item_id' => fn () => LineItem::factory()->for($otherClinic)->create()->id,
        'patient_ids' => fn () => [Patient::factory()->for($otherClinic)->create()->id],
    ][$field]();
    Sanctum::actingAs($worker->user);

    $this->postJson(route('clinic.daily-sessions.store', $worker->clinic_id), [
        'name' => 'Daily physio',
        'clinic_user_id' => $worker->id,
        'line_item_id' => LineItem::factory()->for($worker->clinic)->create()->id,
        'start_time' => '08:00',
        'end_time' => '12:00',
        'weekdays' => [1],
        'starts_on' => '2026-11-02',
        $field => $foreign,
    ])->assertUnprocessable()->assertJsonValidationErrors($field === 'patient_ids' ? 'patient_ids.0' : $field);
})->with(['clinic_user_id', 'line_item_id', 'patient_ids']);

test('a daily session of another clinic is not found', function () {
    $dailySession = createDailySession(ClinicUser::factory()->worker()->create());
    $admin = ClinicUser::factory()->admin()->create();
    Sanctum::actingAs($admin->user);

    $this->getJson(route('clinic.daily-sessions.show', [$admin->clinic_id, $dailySession]))->assertNotFound();
    $this->deleteJson(route('clinic.daily-sessions.destroy', [$admin->clinic_id, $dailySession]))->assertNotFound();
    $this->assertModelExists($dailySession);
});

test('showing a daily session lists the days it runs on for prepaying', function () {
    $worker = ClinicUser::factory()->worker()->create();
    $dailySession = createDailySession($worker, attributes: ['weekdays' => [1], 'ends_on' => '2026-11-30']);
    Sanctum::actingAs($worker->user);

    $this->getJson(route('clinic.daily-sessions.show', [$worker->clinic_id, $dailySession]))
        ->assertOk()
        ->assertJsonCount(5, 'meta.upcoming_days')
        ->assertJsonPath('meta.upcoming_days.0.date', '2026-11-02')
        ->assertJsonPath('meta.upcoming_days.0.clinic_session_id', $dailySession->clinicSessions()->orderBy('starts_at')->value('id'))
        ->assertJsonPath('meta.upcoming_days.4.date', '2026-11-30')
        ->assertJsonPath('meta.upcoming_days.4.clinic_session_id', null);
});

test('an enrolled patient joins the days that have not ended, not past ones', function () {
    $worker = ClinicUser::factory()->worker()->create();
    $dailySession = createDailySession($worker);
    $this->travel(1)->days();
    $patient = Patient::factory()->for($worker->clinic)->create();
    Sanctum::actingAs($worker->user);

    $this->postJson(route('clinic.daily-sessions.patients.store', [$worker->clinic_id, $dailySession]), ['patient_id' => $patient->id])
        ->assertCreated();

    // Ordered explicitly: an unordered query has no guaranteed order, and
    // MySQL and PostgreSQL do not agree on what it happens to be.
    $days = ClinicSession::query()
        ->whereHas('sessionMembers', fn ($query) => $query->where('patient_id', $patient->id))
        ->orderBy('occurs_on')
        ->pluck('occurs_on');

    expect($days->first()->toDateString())->toBe('2026-11-03')
        ->and(Payment::query()->where('patient_id', $patient->id)->count())->toBe($days->count());

    $this->postJson(route('clinic.daily-sessions.patients.store', [$worker->clinic_id, $dailySession]), ['patient_id' => $patient->id])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['patient_id' => 'This patient is already enrolled in the daily session.']);
});

test('removing a patient keeps past days and drops upcoming unpaid ones', function () {
    $worker = ClinicUser::factory()->worker()->create();
    $patient = Patient::factory()->for($worker->clinic)->create();
    $dailySession = createDailySession($worker, [$patient->id]);
    $this->travel(1)->days();
    $enrollment = $dailySession->dailySessionPatients()->sole();
    Sanctum::actingAs($worker->user);

    $this->deleteJson(route('clinic.daily-sessions.patients.destroy', [$worker->clinic_id, $dailySession, $enrollment]))
        ->assertNoContent();

    $remaining = ClinicSessionMember::query()->where('patient_id', $patient->id)->with('clinicSession')->get();

    expect($remaining)->toHaveCount(1)
        ->and($remaining->sole()->clinicSession->occurs_on->toDateString())->toBe('2026-11-02')
        ->and($remaining->sole()->payment->isPastDue())->toBeTrue();
    $this->assertModelMissing($enrollment);
});

test('a patient with a prepaid upcoming day cannot be removed', function () {
    $worker = ClinicUser::factory()->worker()->create();
    $patient = Patient::factory()->for($worker->clinic)->create();
    $dailySession = createDailySession($worker, [$patient->id]);
    Payment::query()->where('patient_id', $patient->id)->firstOrFail()->update(['payment_status_id' => PaymentStatus::Done]);
    Sanctum::actingAs($worker->user);

    $this->deleteJson(route('clinic.daily-sessions.patients.destroy', [$worker->clinic_id, $dailySession, $dailySession->dailySessionPatients()->sole()]))
        ->assertUnprocessable()
        ->assertJsonValidationErrors('patient');

    $this->assertDatabaseCount('daily_session_patients', 1);
});

test('updating the schedule re-plans upcoming days and clears slots that no longer fit, keeping patients on their plans', function () {
    $worker = ClinicUser::factory()->worker()->create();
    $patient = Patient::factory()->for($worker->clinic)->create();
    $dailySession = createDailySession($worker, [$patient->id]);
    $tuesday = $dailySession->clinicSessions()->whereDate('occurs_on', '2026-11-03')->sole();
    $tuesday->sessionMembers()->sole()->update(['starts_at' => '2026-11-03 08:00:00', 'ends_at' => '2026-11-03 08:30:00']);
    Sanctum::actingAs($worker->user);

    $this->patchJson(route('clinic.daily-sessions.update', [$worker->clinic_id, $dailySession]), [
        'start_time' => '09:00',
        'weekdays' => [2, 4],
        'unit_price' => '650.00',
    ])->assertOk();

    $tuesday->refresh();
    // Today (Monday) is already in progress, so only the days after it are re-planned.
    $upcoming = $dailySession->clinicSessions()->whereDate('occurs_on', '>', '2026-11-02')->get();

    expect($upcoming->every(fn (ClinicSession $day) => in_array($day->occurs_on->isoWeekday(), [2, 4], true)))->toBeTrue()
        ->and($tuesday->starts_at->toIso8601String())->toBe('2026-11-03T09:00:00+00:00')
        ->and($tuesday->sessionMembers()->sole()->starts_at)->toBeNull()
        // A new default price is for patients enrolled later; this patient keeps their plan.
        ->and($tuesday->sessionMembers()->sole()->sessionLineItems()->sole()->unit_price)->toBe('500.00');
});

test('a schedule change that would drop a prepaid day is refused', function () {
    $worker = ClinicUser::factory()->worker()->create();
    $patient = Patient::factory()->for($worker->clinic)->create();
    $dailySession = createDailySession($worker, [$patient->id]);
    $wednesday = $dailySession->clinicSessions()->whereDate('occurs_on', '2026-11-04')->sole();
    $wednesday->payments()->update(['payment_status_id' => PaymentStatus::Done]);
    Sanctum::actingAs($worker->user);

    $this->patchJson(route('clinic.daily-sessions.update', [$worker->clinic_id, $dailySession]), ['weekdays' => [1, 2]])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['daily_session' => 'Patients already paid for Wed 4 Nov. Refund those payments first.']);

    expect($dailySession->fresh()->weekdays)->toBe([1, 2, 3, 4, 5]);
});

test('a daily session is deleted with its days unless someone paid', function () {
    $admin = ClinicUser::factory()->admin()->create();
    $patient = Patient::factory()->for($admin->clinic)->create();
    $unpaid = createDailySession($admin, [$patient->id]);
    $paid = createDailySession(ClinicUser::factory()->worker()->create(['clinic_id' => $admin->clinic_id]), [$patient->id], ['name' => 'Evening rehab']);
    $paid->clinicSessions()->first()->payments()->update(['payment_status_id' => PaymentStatus::Done]);
    Sanctum::actingAs($admin->user);

    $this->deleteJson(route('clinic.daily-sessions.destroy', [$admin->clinic_id, $unpaid]))->assertNoContent();
    $this->deleteJson(route('clinic.daily-sessions.destroy', [$admin->clinic_id, $paid]))
        ->assertUnprocessable()
        ->assertJsonValidationErrors('daily_session');

    $this->assertModelMissing($unpaid);
    expect(ClinicSession::query()->where('daily_session_id', $unpaid->id)->exists())->toBeFalse()
        ->and(ActivityLog::query()->where('action', 'daily_session.deleted')->exists())->toBeTrue();
});

test('a day of a daily session cannot be edited or deleted as a regular session', function () {
    $admin = ClinicUser::factory()->admin()->create();
    $day = createDailySession($admin)->clinicSessions()->first();
    Sanctum::actingAs($admin->user);

    $this->patchJson(route('clinic.sessions.update', [$admin->clinic_id, $day]), ['name' => 'Renamed'])
        ->assertUnprocessable()
        ->assertJsonValidationErrors('session');
    $this->deleteJson(route('clinic.sessions.destroy', [$admin->clinic_id, $day]))
        ->assertUnprocessable()
        ->assertJsonValidationErrors('session');
});

test('the schedule command keeps every running daily session scheduled ahead', function () {
    $worker = ClinicUser::factory()->worker()->create();
    $dailySession = createDailySession($worker, attributes: ['weekdays' => [1, 2, 3, 4, 5, 6, 7]]);
    $this->travel(3)->days();

    $this->artisan('clinic:schedule-daily-sessions')->assertSuccessful();

    expect($dailySession->clinicSessions()->max('occurs_on'))->toStartWith('2026-11-19')
        ->and(ActivityLog::query()->where('action', 'daily_session.days_scheduled')->sole()->context['days'])->toBe(3);
});

test('a day of a daily session can only be cancelled by hand', function () {
    $worker = ClinicUser::factory()->worker()->create();
    $days = createDailySession($worker)->clinicSessions()->orderBy('starts_at')->get();
    Sanctum::actingAs($worker->user);

    expect($days[0]->status_id)->toBe(SessionStatus::Ongoing)
        ->and($days[1]->status_id)->toBe(SessionStatus::Pending);

    $this->putJson(route('clinic.sessions.status.update', [$worker->clinic_id, $days[1]]), ['status' => 'ONGOING'])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['status' => 'Days of a daily session start and finish on their own. You can only cancel a day.']);
    $this->putJson(route('clinic.sessions.status.update', [$worker->clinic_id, $days[0]]), ['status' => 'DONE'])
        ->assertUnprocessable();
    $this->putJson(route('clinic.sessions.status.update', [$worker->clinic_id, $days[1]]), ['status' => 'CANCELLED'])
        ->assertOk();
});

test('days start on their date and finish when their hours end', function () {
    $worker = ClinicUser::factory()->worker()->create();
    $dailySession = createDailySession($worker);
    $day = fn (string $date) => $dailySession->clinicSessions()->whereDate('occurs_on', $date)->sole();
    $day('2026-11-04')->update(['status_id' => SessionStatus::Cancelled]);

    // Tuesday 3 November, 10:00 UTC: Monday is over, Tuesday is under way.
    $this->travelTo(now()->setDate(2026, 11, 3)->setTime(10, 0));
    $this->artisan('clinic:advance-daily-session-days')->assertSuccessful();

    expect($day('2026-11-02')->status_id)->toBe(SessionStatus::Done)
        ->and($day('2026-11-03')->status_id)->toBe(SessionStatus::Ongoing)
        ->and($day('2026-11-04')->status_id)->toBe(SessionStatus::Cancelled)
        ->and($day('2026-11-05')->status_id)->toBe(SessionStatus::Pending);
});

test('a day starts on its date in the clinic timezone', function () {
    // Monday 2 November, 18:00 UTC is already Tuesday in Manila.
    $this->travelTo(now()->setDate(2026, 11, 2)->setTime(18, 0));
    $clinic = Clinic::factory()->create(['timezone' => 'Asia/Manila']);
    $worker = ClinicUser::factory()->worker()->create(['clinic_id' => $clinic->id]);

    $tuesday = createDailySession($worker, attributes: ['starts_on' => '2026-11-03'])->clinicSessions()->orderBy('starts_at')->first();

    expect($tuesday->occurs_on->toDateString())->toBe('2026-11-03')
        ->and($tuesday->status_id)->toBe(SessionStatus::Ongoing);
});

test('a session day status can still be changed', function () {
    $worker = ClinicUser::factory()->worker()->create();
    $day = createDailySession($worker)->clinicSessions()->first();
    Sanctum::actingAs($worker->user);

    $this->putJson(route('clinic.sessions.status.update', [$worker->clinic_id, $day]), ['status' => 'CANCELLED'])->assertOk();

    expect($day->fresh()->status_id)->toBe(SessionStatus::Cancelled);
});
