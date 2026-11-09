<?php

use App\Enums\SessionStatus;
use App\Models\ClinicSession;
use App\Models\ClinicUser;
use App\Models\Patient;
use App\Models\SessionLineItem;
use Laravel\Sanctum\Sanctum;

test('a session is created with its patients and stored in UTC', function () {
    $worker = ClinicUser::factory()->worker()->create();
    $patients = Patient::factory()->count(2)->for($worker->clinic)->create();
    Sanctum::actingAs($worker->user);

    $this->postJson(route('clinic.sessions.store', $worker->clinic_id), [
        'name' => 'Group therapy',
        'starts_at' => '2030-01-01T09:00:00+08:00',
        'ends_at' => '2030-01-01T10:00:00+08:00',
        'clinic_user_id' => $worker->id,
        'patient_ids' => $patients->pluck('id')->all(),
    ])->assertCreated()
        ->assertJsonPath('data.status', 'PENDING')
        ->assertJsonPath('data.starts_at', '2030-01-01T01:00:00.000000Z')
        ->assertJsonCount(2, 'data.members');

    $this->assertDatabaseHas('clinic_sessions', [
        'clinic_id' => $worker->clinic_id,
        'clinic_user_id' => $worker->id,
        'starts_at' => '2030-01-01 01:00:00',
        'status_id' => SessionStatus::Pending->value,
    ]);
});

test('a session rejects a member and patients from another clinic', function () {
    $worker = ClinicUser::factory()->worker()->create();
    Sanctum::actingAs($worker->user);

    $this->postJson(route('clinic.sessions.store', $worker->clinic_id), [
        'starts_at' => '2030-01-01T09:00:00Z',
        'ends_at' => '2030-01-01T10:00:00Z',
        'clinic_user_id' => ClinicUser::factory()->worker()->create()->id,
        'patient_ids' => [Patient::factory()->create()->id],
    ])->assertUnprocessable()->assertJsonValidationErrors(['clinic_user_id', 'patient_ids.0']);
});

test('a session must end after it starts', function () {
    $worker = ClinicUser::factory()->worker()->create();
    Sanctum::actingAs($worker->user);

    $this->postJson(route('clinic.sessions.store', $worker->clinic_id), [
        'starts_at' => '2030-01-01T10:00:00Z',
        'ends_at' => '2030-01-01T10:00:00Z',
        'clinic_user_id' => $worker->id,
    ])->assertUnprocessable()->assertJsonValidationErrors(['ends_at' => 'The session must end after it starts.']);
});

test('a member cannot hold two overlapping sessions', function () {
    $session = ClinicSession::factory()->create([
        'starts_at' => '2030-01-01 09:00:00',
        'ends_at' => '2030-01-01 10:00:00',
    ]);
    Sanctum::actingAs($session->clinicUser->user);

    $this->postJson(route('clinic.sessions.store', $session->clinic_id), [
        'starts_at' => '2030-01-01T09:30:00Z',
        'ends_at' => '2030-01-01T10:30:00Z',
        'clinic_user_id' => $session->clinic_user_id,
    ])->assertUnprocessable()->assertJsonValidationErrors(['clinic_user_id' => 'This member already has a session at that time.']);
});

test('a cancelled session does not block the time slot', function () {
    $session = ClinicSession::factory()->status(SessionStatus::Cancelled)->create([
        'starts_at' => '2030-01-01 09:00:00',
        'ends_at' => '2030-01-01 10:00:00',
    ]);
    Sanctum::actingAs($session->clinicUser->user);

    $this->postJson(route('clinic.sessions.store', $session->clinic_id), [
        'starts_at' => '2030-01-01T09:00:00Z',
        'ends_at' => '2030-01-01T10:00:00Z',
        'clinic_user_id' => $session->clinic_user_id,
    ])->assertCreated();
});

test('moving a session does not conflict with itself', function () {
    $session = ClinicSession::factory()->create([
        'starts_at' => '2030-01-01 09:00:00',
        'ends_at' => '2030-01-01 10:00:00',
    ]);
    Sanctum::actingAs($session->clinicUser->user);

    $this->patchJson(route('clinic.sessions.update', [$session->clinic_id, $session]), [
        'ends_at' => '2030-01-01T10:30:00Z',
    ])->assertOk()->assertJsonPath('data.ends_at', '2030-01-01T10:30:00.000000Z');
});

test('a finished session cannot be edited', function () {
    $session = ClinicSession::factory()->status(SessionStatus::Done)->create();
    Sanctum::actingAs($session->clinicUser->user);

    $this->patchJson(route('clinic.sessions.update', [$session->clinic_id, $session]), ['name' => 'Renamed'])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['status' => 'A DONE session cannot be changed.']);
});

test('a session status changes only along allowed transitions', function (SessionStatus $from, string $to, bool $allowed) {
    $session = ClinicSession::factory()->status($from)->create();
    Sanctum::actingAs($session->clinicUser->user);

    $response = $this->putJson(route('clinic.sessions.status.update', [$session->clinic_id, $session]), ['status' => $to]);

    $allowed
        ? $response->assertOk()->assertJsonPath('data.status', $to)
        : $response->assertUnprocessable()->assertJsonValidationErrors('status');
})->with([
    'pending to ongoing' => [SessionStatus::Pending, 'ONGOING', true],
    'pending to cancelled' => [SessionStatus::Pending, 'CANCELLED', true],
    'ongoing to done' => [SessionStatus::Ongoing, 'DONE', true],
    'ongoing to cancelled' => [SessionStatus::Ongoing, 'CANCELLED', true],
    'pending to done' => [SessionStatus::Pending, 'DONE', false],
    'done to ongoing' => [SessionStatus::Done, 'ONGOING', false],
    'cancelled to pending' => [SessionStatus::Cancelled, 'PENDING', false],
]);

test('the session list filters by status and patient', function () {
    $worker = ClinicUser::factory()->worker()->create();
    $patient = Patient::factory()->for($worker->clinic)->create();
    $matching = ClinicSession::factory()->status(SessionStatus::Ongoing)->create(['clinic_user_id' => $worker->id]);
    $matching->patients()->attach($patient);
    ClinicSession::factory()->create(['clinic_user_id' => $worker->id, 'starts_at' => now()->addYear(), 'ends_at' => now()->addYear()->addHour()]);
    Sanctum::actingAs($worker->user);

    $this->getJson(route('clinic.sessions.index', [$worker->clinic_id, 'status' => 'ONGOING', 'patient_id' => $patient->id]))
        ->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.id', $matching->id);
});

test('a session with billed items cannot be deleted', function () {
    $lineItem = SessionLineItem::factory()->create();
    $session = $lineItem->sessionMember->clinicSession;
    Sanctum::actingAs(ClinicUser::factory()->admin()->create(['clinic_id' => $session->clinic_id])->user);

    $this->deleteJson(route('clinic.sessions.destroy', [$session->clinic_id, $session]))
        ->assertUnprocessable()
        ->assertJsonValidationErrors('session');

    $this->assertModelExists($session);
});

test('a session without billed items is deleted with its patients', function () {
    $session = ClinicSession::factory()->create();
    $session->patients()->attach(Patient::factory()->for($session->clinic)->create());
    Sanctum::actingAs(ClinicUser::factory()->admin()->create(['clinic_id' => $session->clinic_id])->user);

    $this->deleteJson(route('clinic.sessions.destroy', [$session->clinic_id, $session]))->assertNoContent();

    $this->assertModelMissing($session);
    $this->assertDatabaseCount('clinic_session_members', 0);
});
