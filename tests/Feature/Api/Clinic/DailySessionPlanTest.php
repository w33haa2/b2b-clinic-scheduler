<?php

use App\Actions\Clinics\SaveDailySession;
use App\Enums\PaymentStatus;
use App\Models\Clinic;
use App\Models\ClinicSession;
use App\Models\ClinicSessionMember;
use App\Models\ClinicUser;
use App\Models\DailySession;
use App\Models\LineItem;
use App\Models\Patient;
use Laravel\Sanctum\Sanctum;

beforeEach(function () {
    // Monday 2 November 2026, 06:00 UTC.
    $this->travelTo(now()->setDate(2026, 11, 2)->setTime(6, 0));

    $this->worker = ClinicUser::factory()->worker()->create();
    $this->therapy = LineItem::factory()->for($this->worker->clinic)->create(['price' => '500.00']);
    $this->assessment = LineItem::factory()->for($this->worker->clinic)->create(['price' => '900.00']);
    [$this->ana, $this->ben] = Patient::factory()->count(2)->for($this->worker->clinic)->create();

    Sanctum::actingAs($this->worker->user);
});

/**
 * Mon–Fri 08:00–12:00 from today. Ana: therapy at 08:00 for 30 min; Ben: assessment at 09:00 for 60 min.
 */
function plannedSession(object $test): DailySession
{
    return app(SaveDailySession::class)->create($test->worker->clinic, [
        'clinic_user_id' => $test->worker->id,
        'name' => 'Daily physio',
        'line_item_id' => $test->therapy->id,
        'unit_price' => '500.00',
        'start_time' => '08:00',
        'end_time' => '12:00',
        'weekdays' => [1, 2, 3, 4, 5],
        'starts_on' => '2026-11-02',
    ], [
        ['patient_id' => $test->ana->id, 'start_time' => '08:00', 'duration_minutes' => 30],
        ['patient_id' => $test->ben->id, 'line_item_id' => $test->assessment->id, 'start_time' => '09:00', 'duration_minutes' => 60],
    ]);
}

function dayMember(DailySession $dailySession, string $date, Patient $patient): ClinicSessionMember
{
    return ClinicSessionMember::query()
        ->where('patient_id', $patient->id)
        ->whereHas('clinicSession', fn ($query) => $query->whereBelongsTo($dailySession)->whereDate('occurs_on', $date))
        ->sole();
}

test('the wizard creates patients with their own service and usual slot on every day', function () {
    $this->postJson(route('clinic.daily-sessions.store', $this->worker->clinic_id), [
        'name' => 'Daily physio',
        'clinic_user_id' => $this->worker->id,
        'line_item_id' => $this->therapy->id,
        'start_time' => '08:00',
        'end_time' => '12:00',
        'weekdays' => [1, 2, 3, 4, 5],
        'starts_on' => '2026-11-02',
        'patients' => [
            ['patient_id' => $this->ana->id, 'start_time' => '08:00', 'duration_minutes' => 30],
            ['patient_id' => $this->ben->id, 'line_item_id' => $this->assessment->id, 'unit_price' => '850.00', 'start_time' => '09:00', 'duration_minutes' => 60],
        ],
    ])->assertCreated()
        ->assertJsonPath('data.enrollments.1.line_item.id', $this->assessment->id)
        ->assertJsonPath('data.enrollments.1.start_time', '09:00')
        ->assertJsonPath('data.enrollments.1.duration_minutes', 60);

    $dailySession = DailySession::query()->sole();
    $ben = dayMember($dailySession, '2026-11-05', $this->ben);

    expect($ben->starts_at->toIso8601String())->toBe('2026-11-05T09:00:00+00:00')
        ->and($ben->ends_at->toIso8601String())->toBe('2026-11-05T10:00:00+00:00')
        ->and($ben->sessionLineItems()->sole()->only(['line_item_id', 'unit_price']))->toBe(['line_item_id' => $this->assessment->id, 'unit_price' => '850.00'])
        ->and(dayMember($dailySession, '2026-11-05', $this->ana)->sessionLineItems()->sole()->unit_price)->toBe('500.00');
});

test('usual slots must be inside the hours and must not overlap', function (array $plans, string $error) {
    $this->postJson(route('clinic.daily-sessions.store', $this->worker->clinic_id), [
        'name' => 'Daily physio',
        'clinic_user_id' => $this->worker->id,
        'line_item_id' => $this->therapy->id,
        'start_time' => '08:00',
        'end_time' => '12:00',
        'weekdays' => [1],
        'starts_on' => '2026-11-02',
        'patients' => array_map(fn (array $plan, int $index): array => ['patient_id' => [$this->ana->id, $this->ben->id][$index], ...$plan], $plans, array_keys($plans)),
    ])->assertUnprocessable()->assertJsonValidationErrors($error);
})->with([
    'outside the hours' => [[['start_time' => '11:30', 'duration_minutes' => 60]], 'patients.0.start_time'],
    'overlapping' => [[['start_time' => '08:00', 'duration_minutes' => 60], ['start_time' => '08:30', 'duration_minutes' => 30]], 'patients.1.start_time'],
]);

test('a plan cannot use a service of another clinic', function () {
    $this->postJson(route('clinic.daily-sessions.store', $this->worker->clinic_id), [
        'name' => 'Daily physio',
        'clinic_user_id' => $this->worker->id,
        'line_item_id' => $this->therapy->id,
        'start_time' => '08:00',
        'end_time' => '12:00',
        'weekdays' => [1],
        'starts_on' => '2026-11-02',
        'patients' => [['patient_id' => $this->ana->id, 'line_item_id' => LineItem::factory()->for(Clinic::factory())->create()->id]],
    ])->assertUnprocessable()->assertJsonValidationErrors('patients.0.line_item_id');
});

test('a patient whose usual slot is taken that day waits to be plotted', function () {
    ClinicSession::factory()->create([
        'clinic_user_id' => $this->worker->id,
        'starts_at' => '2026-11-04 09:30:00',
        'ends_at' => '2026-11-04 10:30:00',
    ]);

    $dailySession = plannedSession($this);

    expect(dayMember($dailySession, '2026-11-04', $this->ben)->starts_at)->toBeNull()
        ->and(dayMember($dailySession, '2026-11-05', $this->ben)->starts_at)->not->toBeNull()
        ->and(dayMember($dailySession, '2026-11-04', $this->ana)->starts_at)->not->toBeNull();

    $this->getJson(route('clinic.sessions.show', [$this->worker->clinic_id, dayMember($dailySession, '2026-11-04', $this->ben)->clinic_session_id]))
        ->assertJsonPath('meta.busy.0.starts_at', '2026-11-04T09:30:00.000000Z');
});

test('a change on one day stays on that day while the regular plan moves the others', function () {
    $dailySession = plannedSession($this);
    $tuesday = dayMember($dailySession, '2026-11-03', $this->ana);

    $this->putJson(route('clinic.sessions.members.slot.update', [$this->worker->clinic_id, $tuesday->clinic_session_id, $tuesday]), [
        'starts_at' => '2026-11-03T11:00:00Z',
        'ends_at' => '2026-11-03T11:30:00Z',
    ])->assertOk()->assertJsonPath('data.customised', true);

    $enrollment = $dailySession->dailySessionPatients()->where('patient_id', $this->ana->id)->sole();

    $this->putJson(route('clinic.daily-sessions.patients.update', [$this->worker->clinic_id, $dailySession, $enrollment]), [
        'start_time' => '10:00',
        'duration_minutes' => 45,
    ])->assertOk()->assertJsonPath('data.start_time', '10:00');

    expect($tuesday->fresh()->starts_at->toIso8601String())->toBe('2026-11-03T11:00:00+00:00')
        ->and(dayMember($dailySession, '2026-11-04', $this->ana)->starts_at->toIso8601String())->toBe('2026-11-04T10:00:00+00:00')
        ->and(dayMember($dailySession, '2026-11-04', $this->ana)->ends_at->toIso8601String())->toBe('2026-11-04T10:45:00+00:00');

    Sanctum::actingAs(ClinicUser::factory()->admin()->create(['clinic_id' => $this->worker->clinic_id])->user);
    $this->getJson(route('clinic.activity.index', [$this->worker->clinic_id, 'category' => 'schedule']))
        ->assertJsonPath('data.0.action', 'daily_session_patient.plan_changed')
        ->assertJsonPath('data.0.description', "Changed the regular plan of {$this->ana->first_name} {$this->ana->last_name} in daily session Daily physio")
        ->assertJsonPath('data.1.action', 'session_patient.plotted');
});

test('changing the regular service updates unpaid upcoming days only', function () {
    $dailySession = plannedSession($this);
    dayMember($dailySession, '2026-11-04', $this->ana)->payment->update(['payment_status_id' => PaymentStatus::Done]);
    $enrollment = $dailySession->dailySessionPatients()->where('patient_id', $this->ana->id)->sole();

    $this->putJson(route('clinic.daily-sessions.patients.update', [$this->worker->clinic_id, $dailySession, $enrollment]), [
        'line_item_id' => $this->assessment->id,
    ])->assertOk()->assertJsonPath('data.unit_price', '900.00');

    expect(dayMember($dailySession, '2026-11-05', $this->ana)->sessionLineItems()->sole()->line_item_id)->toBe($this->assessment->id)
        ->and(dayMember($dailySession, '2026-11-04', $this->ana)->sessionLineItems()->sole()->line_item_id)->toBe($this->therapy->id);
});

test('a day can bill a different service, only while unpaid', function () {
    $dailySession = plannedSession($this);
    $wednesday = dayMember($dailySession, '2026-11-04', $this->ana);
    $route = route('clinic.sessions.members.slot.update', [$this->worker->clinic_id, $wednesday->clinic_session_id, $wednesday]);

    $this->putJson($route, ['line_item_id' => $this->assessment->id])
        ->assertOk()
        ->assertJsonPath('data.line_items.0.line_item.id', $this->assessment->id)
        ->assertJsonPath('data.line_items.0.unit_price', '900.00');

    expect(dayMember($dailySession, '2026-11-05', $this->ana)->sessionLineItems()->sole()->line_item_id)->toBe($this->therapy->id);

    $wednesday->payment->update(['payment_status_id' => PaymentStatus::Done]);

    $this->putJson($route, ['line_item_id' => $this->therapy->id])
        ->assertUnprocessable()
        ->assertJsonValidationErrors('line_item_id');
});

test('reset puts a patient back on the regular plan for the day', function () {
    $dailySession = plannedSession($this);
    $wednesday = dayMember($dailySession, '2026-11-04', $this->ana);
    $route = route('clinic.sessions.members.slot.update', [$this->worker->clinic_id, $wednesday->clinic_session_id, $wednesday]);
    $this->putJson($route, ['starts_at' => null, 'ends_at' => null, 'line_item_id' => $this->assessment->id])->assertOk();

    $this->putJson($route, ['reset' => true])
        ->assertOk()
        ->assertJsonPath('data.customised', false)
        ->assertJsonPath('data.starts_at', '2026-11-04T08:00:00.000000Z')
        ->assertJsonPath('data.line_items.0.line_item.id', $this->therapy->id);
});

test('a usual slot cannot overlap another patient\'s or leave the hours', function () {
    $dailySession = plannedSession($this);
    $enrollment = $dailySession->dailySessionPatients()->where('patient_id', $this->ana->id)->sole();
    $route = route('clinic.daily-sessions.patients.update', [$this->worker->clinic_id, $dailySession, $enrollment]);

    $this->putJson($route, ['start_time' => '09:30', 'duration_minutes' => 30])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['start_time' => 'Another patient already has this time as their usual slot.']);
    $this->putJson($route, ['start_time' => '11:45', 'duration_minutes' => 30])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['start_time' => 'The usual slot must be inside the daily hours.']);
});

test('a plan of another daily session or clinic is not found', function () {
    $dailySession = plannedSession($this);
    $other = app(SaveDailySession::class)->create($this->worker->clinic, [
        'clinic_user_id' => ClinicUser::factory()->worker()->create(['clinic_id' => $this->worker->clinic_id])->id,
        'name' => 'Evening',
        'line_item_id' => $this->therapy->id,
        'unit_price' => '500.00',
        'start_time' => '13:00',
        'end_time' => '15:00',
        'weekdays' => [1],
        'starts_on' => '2026-11-02',
    ], [$this->ana->id]);
    $foreignAdmin = ClinicUser::factory()->admin()->create();

    $this->putJson(route('clinic.daily-sessions.patients.update', [$this->worker->clinic_id, $dailySession, $other->dailySessionPatients()->sole()]), ['start_time' => null])
        ->assertNotFound();

    Sanctum::actingAs($foreignAdmin->user);
    $this->putJson(route('clinic.daily-sessions.patients.update', [$foreignAdmin->clinic_id, $dailySession, $dailySession->dailySessionPatients()->first()]), ['start_time' => null])
        ->assertNotFound();
});

test('a staff member can hold only one daily session', function () {
    $dailySession = plannedSession($this);
    $colleague = ClinicUser::factory()->worker()->create(['clinic_id' => $this->worker->clinic_id]);
    $colleagueSession = app(SaveDailySession::class)->create($this->worker->clinic, [
        'clinic_user_id' => $colleague->id,
        'name' => 'Evening',
        'line_item_id' => $this->therapy->id,
        'unit_price' => '500.00',
        'start_time' => '13:00',
        'end_time' => '15:00',
        'weekdays' => [1],
        'starts_on' => '2026-11-02',
    ]);

    $this->postJson(route('clinic.daily-sessions.store', $this->worker->clinic_id), [
        'name' => 'Second one',
        'clinic_user_id' => $this->worker->id,
        'line_item_id' => $this->therapy->id,
        'start_time' => '13:00',
        'end_time' => '15:00',
        'weekdays' => [1],
        'starts_on' => '2026-11-02',
    ])->assertUnprocessable()->assertJsonValidationErrors([
        'clinic_user_id' => 'This staff member already has a daily session. Edit it instead of creating another.',
    ]);
    $this->patchJson(route('clinic.daily-sessions.update', [$this->worker->clinic_id, $colleagueSession]), ['clinic_user_id' => $this->worker->id])
        ->assertUnprocessable()
        ->assertJsonValidationErrors('clinic_user_id');
    $this->patchJson(route('clinic.daily-sessions.update', [$this->worker->clinic_id, $dailySession]), ['clinic_user_id' => $this->worker->id, 'name' => 'Renamed'])
        ->assertOk();
});

test('the wizard update syncs patients: adds, changes plans and removes', function () {
    $dailySession = plannedSession($this);
    $cara = Patient::factory()->for($this->worker->clinic)->create();

    $this->putJson(route('clinic.daily-sessions.update', [$this->worker->clinic_id, $dailySession]), [
        'patients' => [
            ['patient_id' => $this->ana->id, 'start_time' => '10:00', 'duration_minutes' => 30],
            ['patient_id' => $cara->id, 'line_item_id' => $this->assessment->id, 'start_time' => '11:00', 'duration_minutes' => 60],
        ],
    ])->assertOk()->assertJsonCount(2, 'data.enrollments');

    expect(dayMember($dailySession, '2026-11-04', $this->ana)->starts_at->toIso8601String())->toBe('2026-11-04T10:00:00+00:00')
        ->and(dayMember($dailySession, '2026-11-04', $cara)->sessionLineItems()->sole()->line_item_id)->toBe($this->assessment->id)
        ->and(ClinicSessionMember::query()->where('patient_id', $this->ben->id)->whereHas('clinicSession', fn ($query) => $query->where('starts_at', '>', now()))->exists())->toBeFalse();
});

test('a wizard update that drops a patient who prepaid saves nothing', function () {
    $dailySession = plannedSession($this);
    dayMember($dailySession, '2026-11-05', $this->ben)->payment->update(['payment_status_id' => PaymentStatus::Done]);

    $this->putJson(route('clinic.daily-sessions.update', [$this->worker->clinic_id, $dailySession]), [
        'name' => 'Renamed',
        'patients' => [['patient_id' => $this->ana->id, 'start_time' => '10:00', 'duration_minutes' => 30]],
    ])->assertUnprocessable()->assertJsonValidationErrors('patient');

    expect($dailySession->fresh()->name)->toBe('Daily physio')
        ->and($dailySession->dailySessionPatients()->count())->toBe(2)
        ->and(dayMember($dailySession, '2026-11-04', $this->ana)->starts_at->toIso8601String())->toBe('2026-11-04T08:00:00+00:00');
});

test('the days of one daily session can be listed', function () {
    $dailySession = plannedSession($this);

    $this->getJson(route('clinic.sessions.index', [$this->worker->clinic_id, 'daily_session_id' => $dailySession->id, 'from' => '2026-11-03T00:00:00Z', 'to' => '2026-11-04T00:00:00Z']))
        ->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.occurs_on', '2026-11-03');
});

test('today can be rearranged while it runs, and the regular plan leaves it alone', function () {
    $dailySession = plannedSession($this);
    $this->travelTo(now()->setDate(2026, 11, 2)->setTime(10, 30));
    $today = dayMember($dailySession, '2026-11-02', $this->ana);
    $route = route('clinic.sessions.members.slot.update', [$this->worker->clinic_id, $today->clinic_session_id, $today]);

    $this->putJson($route, ['starts_at' => '2026-11-02T11:00:00Z', 'ends_at' => '2026-11-02T11:30:00Z'])->assertOk();
    // Recording what happened earlier in the day is allowed too.
    $this->putJson($route, ['starts_at' => '2026-11-02T08:30:00Z', 'ends_at' => '2026-11-02T09:00:00Z'])->assertOk();

    $walkIn = Patient::factory()->for($this->worker->clinic)->create();
    $this->postJson(route('clinic.sessions.members.store', [$this->worker->clinic_id, $today->clinic_session_id]), ['patient_id' => $walkIn->id])
        ->assertCreated()
        ->assertJsonPath('data.line_items.0.line_item.id', $this->therapy->id);

    $this->putJson(route('clinic.daily-sessions.patients.update', [$this->worker->clinic_id, $dailySession, $dailySession->dailySessionPatients()->where('patient_id', $this->ben->id)->sole()]), [
        'start_time' => '11:30',
        'duration_minutes' => 30,
    ])->assertOk();

    expect($today->fresh()->starts_at->toIso8601String())->toBe('2026-11-02T08:30:00+00:00')
        ->and(dayMember($dailySession, '2026-11-02', $this->ben)->starts_at->toIso8601String())->toBe('2026-11-02T09:00:00+00:00')
        ->and(dayMember($dailySession, '2026-11-03', $this->ben)->starts_at->toIso8601String())->toBe('2026-11-03T11:30:00+00:00');
});
