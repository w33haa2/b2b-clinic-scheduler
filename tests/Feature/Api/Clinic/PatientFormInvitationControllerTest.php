<?php

use App\Models\ClinicRole;
use App\Models\ClinicUser;
use App\Models\Patient;
use App\Models\PatientFormInvitation;
use Laravel\Sanctum\Sanctum;

test('issuing an intake link returns the link once and stores only its hash', function () {
    $worker = ClinicUser::factory()->worker()->create();
    Sanctum::actingAs($worker->user);

    $response = $this->postJson(route('clinic.patient-form-invitations.store', $worker->clinic_id), [
        'name' => 'Ana Reyes',
        'email' => 'ana@example.com',
    ])->assertCreated()
        ->assertJsonPath('data.status', 'pending')
        ->assertJsonPath('data.patient_id', null);

    $url = $response->json('data.url');
    $token = basename(parse_url($url, PHP_URL_PATH));

    expect($url)->toContain('/patient-form/')
        ->and($token)->toHaveLength(64);

    $invitation = PatientFormInvitation::query()->sole();

    expect($invitation->token_hash)->toBe(PatientFormInvitation::hashToken($token))
        ->and($invitation->token_hash)->not->toBe($token)
        ->and($invitation->created_by_clinic_user_id)->toBe($worker->id);

    // The link is never returned again.
    $this->getJson(route('clinic.patient-form-invitations.index', $worker->clinic_id))
        ->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonMissingPath('data.0.url');
});

test('a link can be aimed at an existing patient', function () {
    $worker = ClinicUser::factory()->worker()->create();
    $patient = Patient::factory()->for($worker->clinic)->create();
    Sanctum::actingAs($worker->user);

    $this->postJson(route('clinic.patient-form-invitations.store', $worker->clinic_id), ['patient_id' => $patient->id])
        ->assertCreated()
        ->assertJsonPath('data.patient_id', $patient->id);
});

test('a link cannot be aimed at a patient of another clinic', function () {
    $worker = ClinicUser::factory()->worker()->create();
    $foreign = Patient::factory()->create();
    Sanctum::actingAs($worker->user);

    $this->postJson(route('clinic.patient-form-invitations.store', $worker->clinic_id), ['patient_id' => $foreign->id])
        ->assertUnprocessable()
        ->assertJsonValidationErrors('patient_id');
});

test('resending issues a new link and stops the old one working', function () {
    $worker = ClinicUser::factory()->worker()->create();
    $invitation = PatientFormInvitation::factory()->for($worker->clinic)->withToken('first-token')->create();
    Sanctum::actingAs($worker->user);

    $url = $this->postJson(route('clinic.patient-form-invitations.resend', [$worker->clinic_id, $invitation]))
        ->assertOk()
        ->json('data.url');

    expect(PatientFormInvitation::findByToken('first-token'))->toBeNull()
        ->and(PatientFormInvitation::findByToken(basename(parse_url($url, PHP_URL_PATH))))->not->toBeNull();
});

test('a link that has been filled in cannot be resent', function () {
    $worker = ClinicUser::factory()->worker()->create();
    $invitation = PatientFormInvitation::factory()->for($worker->clinic)->submitted()->create();
    Sanctum::actingAs($worker->user);

    $this->postJson(route('clinic.patient-form-invitations.resend', [$worker->clinic_id, $invitation]))->assertUnprocessable();
});

test('cancelling a link stops it working', function () {
    $worker = ClinicUser::factory()->worker()->create();
    $invitation = PatientFormInvitation::factory()->for($worker->clinic)->withToken('live-token')->create();
    Sanctum::actingAs($worker->user);

    $this->deleteJson(route('clinic.patient-form-invitations.destroy', [$worker->clinic_id, $invitation]))->assertNoContent();

    expect($invitation->refresh()->status())->toBe('revoked');
});

test('links are filtered by status', function () {
    $worker = ClinicUser::factory()->worker()->create();
    PatientFormInvitation::factory()->for($worker->clinic)->create();
    PatientFormInvitation::factory()->for($worker->clinic)->submitted()->create();
    PatientFormInvitation::factory()->for($worker->clinic)->revoked()->create();
    PatientFormInvitation::factory()->for($worker->clinic)->expired()->create();
    Sanctum::actingAs($worker->user);

    foreach (['pending', 'submitted', 'revoked', 'expired'] as $status) {
        $this->getJson(route('clinic.patient-form-invitations.index', [$worker->clinic_id, 'status' => $status]))
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.status', $status);
    }
});

test('links of another clinic are out of reach', function () {
    $foreign = PatientFormInvitation::factory()->create();
    $worker = ClinicUser::factory()->worker()->create();
    Sanctum::actingAs($worker->user);

    $this->getJson(route('clinic.patient-form-invitations.index', $worker->clinic_id))->assertOk()->assertJsonCount(0, 'data');
    $this->deleteJson(route('clinic.patient-form-invitations.destroy', [$worker->clinic_id, $foreign]))->assertNotFound();
});

test('a role without the intake link permissions cannot issue one', function () {
    $role = ClinicRole::factory()->withPermissions(['clinic.patients.index'])->create();
    $member = ClinicUser::factory()->withRole($role)->create();
    Sanctum::actingAs($member->user);

    $this->getJson(route('clinic.patient-form-invitations.index', $role->clinic_id))->assertForbidden();
    $this->postJson(route('clinic.patient-form-invitations.store', $role->clinic_id), [])->assertForbidden();
});
