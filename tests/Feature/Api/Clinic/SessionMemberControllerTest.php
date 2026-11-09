<?php

use App\Enums\PaymentStatus;
use App\Enums\SessionStatus;
use App\Models\ClinicSession;
use App\Models\ClinicSessionMember;
use App\Models\Patient;
use App\Models\SessionLineItem;
use Laravel\Sanctum\Sanctum;

test('a patient is added to a session', function () {
    $session = ClinicSession::factory()->create();
    $patient = Patient::factory()->for($session->clinic)->create();
    Sanctum::actingAs($session->clinicUser->user);

    $this->postJson(route('clinic.sessions.members.store', [$session->clinic_id, $session]), ['patient_id' => $patient->id])
        ->assertCreated()
        ->assertJsonPath('data.payment_status', 'PENDING');

    $this->assertDatabaseHas('clinic_session_members', ['clinic_session_id' => $session->id, 'patient_id' => $patient->id]);
    $this->assertDatabaseHas('payments', ['clinic_session_id' => $session->id, 'patient_id' => $patient->id, 'payment_status_id' => PaymentStatus::Pending->value]);
});

test('a patient cannot be added to the same session twice', function () {
    $sessionMember = ClinicSessionMember::factory()->create();
    $session = $sessionMember->clinicSession;
    Sanctum::actingAs($session->clinicUser->user);

    $this->postJson(route('clinic.sessions.members.store', [$session->clinic_id, $session]), ['patient_id' => $sessionMember->patient_id])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['patient_id' => 'This patient is already in the session.']);
});

test('patients cannot be added to a finished session', function () {
    $session = ClinicSession::factory()->status(SessionStatus::Done)->create();
    Sanctum::actingAs($session->clinicUser->user);

    $this->postJson(route('clinic.sessions.members.store', [$session->clinic_id, $session]), [
        'patient_id' => Patient::factory()->for($session->clinic)->create()->id,
    ])->assertUnprocessable()->assertJsonValidationErrors('status');
});

test('a patient who paid cannot be removed from a session', function () {
    $sessionMember = ClinicSessionMember::factory()->paymentStatus(PaymentStatus::Done)->create();
    SessionLineItem::factory()->for($sessionMember, 'sessionMember')->create();
    $session = $sessionMember->clinicSession;
    Sanctum::actingAs($session->clinicUser->user);

    $this->deleteJson(route('clinic.sessions.members.destroy', [$session->clinic_id, $session, $sessionMember]))
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['session_member' => 'A patient whose payment is DONE cannot be removed from the session.']);
});

test('an unpaid patient is removed with their billed items and payment', function () {
    $sessionLineItem = SessionLineItem::factory()->create();
    $sessionMember = $sessionLineItem->sessionMember;
    $session = $sessionMember->clinicSession;
    Sanctum::actingAs($session->clinicUser->user);

    $this->deleteJson(route('clinic.sessions.members.destroy', [$session->clinic_id, $session, $sessionMember]))->assertNoContent();

    $this->assertModelMissing($sessionMember);
    $this->assertModelMissing($sessionLineItem);
    $this->assertDatabaseCount('payments', 0);
});

test('a patient without billed items is removed from a session', function () {
    $sessionMember = ClinicSessionMember::factory()->create();
    $session = $sessionMember->clinicSession;
    Sanctum::actingAs($session->clinicUser->user);

    $this->deleteJson(route('clinic.sessions.members.destroy', [$session->clinic_id, $session, $sessionMember]))->assertNoContent();

    $this->assertModelMissing($sessionMember);
});
