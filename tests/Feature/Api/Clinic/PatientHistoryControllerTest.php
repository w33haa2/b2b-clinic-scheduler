<?php

use App\Enums\PaymentStatus;
use App\Enums\ProgressRating;
use App\Models\ClinicRole;
use App\Models\ClinicSession;
use App\Models\ClinicSessionMember;
use App\Models\ClinicUser;
use App\Models\Patient;
use App\Models\PatientProgressNote;
use App\Models\SessionLineItem;
use Laravel\Sanctum\Sanctum;

test('a patient history weaves sessions, progress notes and record changes into one timeline', function () {
    $worker = ClinicUser::factory()->worker()->create();
    $patient = Patient::factory()->for($worker->clinic)->create(['first_name' => 'Ana', 'last_name' => 'Reyes']);

    $session = ClinicSession::factory()->create([
        'clinic_user_id' => $worker->id,
        'clinic_id' => $worker->clinic_id,
        'starts_at' => '2026-10-20 09:00:00',
        'ends_at' => '2026-10-20 10:00:00',
    ]);
    $sessionMember = ClinicSessionMember::factory()->for($session)->paymentStatus(PaymentStatus::Done)->create(['patient_id' => $patient->id]);
    SessionLineItem::factory()->for($sessionMember, 'sessionMember')->create(['quantity' => 2, 'unit_price' => '500.00']);

    PatientProgressNote::factory()->on($patient, '2026-10-21')->by($worker)->rated(ProgressRating::Improved)->create(['summary' => 'Walking further']);

    Sanctum::actingAs($worker->user);
    $this->patchJson(route('clinic.patients.update', [$worker->clinic_id, $patient]), ['phone' => '0917 000 0000'])->assertOk();

    $entries = collect($this->getJson(route('clinic.patients.history.index', [$worker->clinic_id, $patient]))
        ->assertOk()
        ->json('data.entries'));

    expect($entries->pluck('type')->unique()->sort()->values()->all())
        ->toBe(['progress_note', 'record_change', 'session']);

    $sessionEntry = $entries->firstWhere('type', 'session');
    $noteEntry = $entries->firstWhere('type', 'progress_note');

    expect($sessionEntry['payment_status'])->toBe('DONE')
        ->and($sessionEntry['held_by'])->toBe($worker->user->name)
        ->and($sessionEntry['items'])->toHaveCount(1)
        ->and($noteEntry['progress'])->toBe('IMPROVED')
        ->and($noteEntry['direction'])->toBe(1)
        ->and($noteEntry['title'])->toBe('Walking further');
});

test('the history is newest first', function () {
    $worker = ClinicUser::factory()->worker()->create();
    $patient = Patient::factory()->for($worker->clinic)->create();
    PatientProgressNote::factory()->on($patient, '2026-10-01')->by($worker)->create(['summary' => 'Older']);
    PatientProgressNote::factory()->on($patient, '2026-10-20')->by($worker)->create(['summary' => 'Newer']);
    Sanctum::actingAs($worker->user);

    $entries = collect($this->getJson(route('clinic.patients.history.index', [$worker->clinic_id, $patient]))->json('data.entries'))
        ->where('type', 'progress_note')
        ->values();

    expect($entries[0]['title'])->toBe('Newer')
        ->and($entries[1]['title'])->toBe('Older');
});

test('a record change says a field changed without repeating what it became', function () {
    $worker = ClinicUser::factory()->worker()->create();
    $patient = Patient::factory()->for($worker->clinic)->create();
    Sanctum::actingAs($worker->user);

    $this->patchJson(route('clinic.patients.update', [$worker->clinic_id, $patient]), ['email' => 'secret@example.com'])->assertOk();

    $entry = collect($this->getJson(route('clinic.patients.history.index', [$worker->clinic_id, $patient]))->json('data.entries'))
        ->firstWhere('type', 'record_change');

    expect($entry['changes'])->toBe(['email']);
    $this->assertDatabaseMissing('activity_logs', ['subject_label' => 'secret@example.com']);
});

test('the history of a patient in another clinic is not found', function () {
    $foreign = Patient::factory()->create();
    $worker = ClinicUser::factory()->worker()->create();
    Sanctum::actingAs($worker->user);

    $this->getJson(route('clinic.patients.history.index', [$worker->clinic_id, $foreign]))->assertNotFound();
});

test('a role without the history permission cannot read it', function () {
    $role = ClinicRole::factory()->withPermissions(['clinic.patients.index'])->create();
    $member = ClinicUser::factory()->withRole($role)->create();
    $patient = Patient::factory()->for($member->clinic)->create();
    Sanctum::actingAs($member->user);

    $this->getJson(route('clinic.patients.history.index', [$role->clinic_id, $patient]))->assertForbidden();
});
