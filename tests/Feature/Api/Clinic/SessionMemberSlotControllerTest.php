<?php

use App\Actions\Clinics\SaveDailySession;
use App\Models\ClinicSession;
use App\Models\ClinicSessionMember;
use App\Models\ClinicUser;
use App\Models\LineItem;
use App\Models\Patient;
use Laravel\Sanctum\Sanctum;

beforeEach(function () {
    // Monday 2 November 2026, 06:00 UTC.
    $this->travelTo(now()->setDate(2026, 11, 2)->setTime(6, 0));

    $this->worker = ClinicUser::factory()->worker()->create();
    $patients = Patient::factory()->count(2)->for($this->worker->clinic)->create();

    $dailySession = app(SaveDailySession::class)->create($this->worker->clinic, [
        'clinic_user_id' => $this->worker->id,
        'name' => 'Daily physio',
        'line_item_id' => LineItem::factory()->for($this->worker->clinic)->create()->id,
        'unit_price' => '500.00',
        'start_time' => '08:00',
        'end_time' => '12:00',
        'weekdays' => [1],
        'starts_on' => '2026-11-02',
    ], $patients->pluck('id')->all());

    $this->day = $dailySession->clinicSessions()->whereDate('occurs_on', '2026-11-02')->sole();
    [$this->first, $this->second] = $this->day->sessionMembers()->orderBy('id')->get();

    Sanctum::actingAs($this->worker->user);
});

function slotRoute(ClinicSession $day, ClinicSessionMember $sessionMember): string
{
    return route('clinic.sessions.members.slot.update', [$day->clinic_id, $day, $sessionMember]);
}

test('a patient is plotted into a free slot and the vacancies shrink', function () {
    $this->putJson(slotRoute($this->day, $this->first), [
        'starts_at' => '2026-11-02T16:30:00+08:00',
        'ends_at' => '2026-11-02T17:00:00+08:00',
    ])->assertOk()->assertJsonPath('data.starts_at', '2026-11-02T08:30:00.000000Z');

    $this->getJson(route('clinic.sessions.show', [$this->day->clinic_id, $this->day]))
        ->assertOk()
        ->assertJsonPath('data.daily_session.name', 'Daily physio')
        ->assertJsonCount(2, 'meta.vacancies')
        ->assertJsonPath('meta.vacancies.0.minutes', 30)
        ->assertJsonPath('meta.vacancies.1.minutes', 180);
});

test('a slot cannot overlap another plotted patient or a session of the same staff member', function (string $blocker) {
    if ($blocker === 'plotted patient') {
        $this->second->update(['starts_at' => '2026-11-02 09:00:00', 'ends_at' => '2026-11-02 10:00:00']);
    } else {
        ClinicSession::factory()->create([
            'clinic_user_id' => $this->worker->id,
            'starts_at' => '2026-11-02 09:30:00',
            'ends_at' => '2026-11-02 10:30:00',
        ]);
    }

    $this->putJson(slotRoute($this->day, $this->first), [
        'starts_at' => '2026-11-02T09:45:00Z',
        'ends_at' => '2026-11-02T10:15:00Z',
    ])->assertUnprocessable()->assertJsonValidationErrors([
        'starts_at' => 'The staff member is not free at that time. Pick a vacant slot.',
    ]);
})->with(['plotted patient', 'regular session']);

test('a slot must be inside the daily hours', function () {
    $this->putJson(slotRoute($this->day, $this->first), [
        'starts_at' => '2026-11-02T11:45:00Z',
        'ends_at' => '2026-11-02T12:15:00Z',
    ])->assertUnprocessable()->assertJsonValidationErrors('starts_at');
});

test('a plotted patient is unplotted with null times', function () {
    $this->first->update(['starts_at' => '2026-11-02 09:00:00', 'ends_at' => '2026-11-02 10:00:00']);

    $this->putJson(slotRoute($this->day, $this->first), ['starts_at' => null, 'ends_at' => null])
        ->assertOk()
        ->assertJsonPath('data.starts_at', null);
});

test('patients of a regular session cannot be plotted', function () {
    $sessionMember = ClinicSessionMember::factory()->create();
    $session = $sessionMember->clinicSession;
    Sanctum::actingAs($session->clinicUser->user);

    $this->putJson(slotRoute($session, $sessionMember), [
        'starts_at' => $session->starts_at->toIso8601String(),
        'ends_at' => $session->ends_at->toIso8601String(),
    ])->assertUnprocessable()->assertJsonValidationErrors('starts_at');
});

test('booking a regular session over a plotted patient counts as a double booking', function () {
    $this->first->update(['starts_at' => '2026-11-02 09:00:00', 'ends_at' => '2026-11-02 10:00:00']);

    $this->postJson(route('clinic.sessions.store', $this->worker->clinic_id), [
        'clinic_user_id' => $this->worker->id,
        'starts_at' => '2026-11-02T09:30:00Z',
        'ends_at' => '2026-11-02T10:30:00Z',
    ])->assertUnprocessable()->assertJsonValidationErrors('clinic_user_id');

    $this->postJson(route('clinic.sessions.store', $this->worker->clinic_id), [
        'clinic_user_id' => $this->worker->id,
        'starts_at' => '2026-11-02T10:00:00Z',
        'ends_at' => '2026-11-02T11:00:00Z',
    ])->assertCreated();
});

test('a patient of another clinic session is not found', function () {
    $other = ClinicSessionMember::factory()->create();

    $this->putJson(slotRoute($this->day, $other), ['starts_at' => null, 'ends_at' => null])->assertNotFound();
});
