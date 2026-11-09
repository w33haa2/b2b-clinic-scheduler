<?php

use App\Enums\ProgressRating;
use App\Models\ClinicRole;
use App\Models\ClinicUser;
use App\Models\Patient;
use App\Models\PatientProgressMetric;
use App\Models\PatientProgressNote;
use Laravel\Sanctum\Sanctum;

test('a worker writes a progress note with measurements', function () {
    $worker = ClinicUser::factory()->worker()->create();
    $patient = Patient::factory()->for($worker->clinic)->create();
    Sanctum::actingAs($worker->user);

    $this->postJson(route('clinic.patients.progress-notes.store', [$worker->clinic_id, $patient]), [
        'recorded_on' => '2026-11-02',
        'progress' => 'BASELINE',
        'summary' => 'First assessment',
        'body' => 'Reports sharp pain on the stairs.',
        'plan' => 'Home exercises twice a day.',
        'metrics' => [
            ['name' => 'Pain', 'value' => 8, 'unit' => '/10'],
            ['name' => 'Knee flexion', 'value' => 85, 'unit' => 'deg'],
        ],
    ])->assertCreated()
        ->assertJsonPath('data.progress', 'BASELINE')
        ->assertJsonPath('data.progress_label', 'Baseline')
        ->assertJsonPath('data.direction', 0)
        ->assertJsonPath('data.author.id', $worker->id)
        ->assertJsonCount(2, 'data.metrics');

    $this->assertDatabaseHas('patient_progress_notes', [
        'clinic_id' => $worker->clinic_id,
        'patient_id' => $patient->id,
        'clinic_user_id' => $worker->id,
        'progress_rating_id' => ProgressRating::Baseline->value,
    ]);
    $this->assertDatabaseHas('patient_progress_metrics', ['name' => 'Pain', 'value' => '8.00', 'unit' => '/10']);
});

test('a patient history reads oldest first, with a trend and chartable measurements', function () {
    $worker = ClinicUser::factory()->worker()->create();
    $patient = Patient::factory()->for($worker->clinic)->create();

    foreach ([
        ['2026-10-01', ProgressRating::Baseline, 8],
        ['2026-10-15', ProgressRating::Improved, 5],
        ['2026-10-29', ProgressRating::Improved, 3],
    ] as [$date, $rating, $pain]) {
        $note = PatientProgressNote::factory()->on($patient, $date)->by($worker)->rated($rating)->create();
        PatientProgressMetric::factory()->for($note, 'patientProgressNote')->create(['name' => 'Pain', 'value' => $pain, 'unit' => '/10']);
    }

    Sanctum::actingAs($worker->user);

    $response = $this->getJson(route('clinic.patients.progress-notes.index', [$worker->clinic_id, $patient]))
        ->assertOk()
        ->assertJsonCount(3, 'data')
        ->assertJsonPath('data.0.recorded_on', '2026-10-01')
        ->assertJsonPath('data.2.recorded_on', '2026-10-29')
        ->assertJsonPath('meta.trend.notes', 3)
        ->assertJsonPath('meta.trend.improved', 2)
        ->assertJsonPath('meta.trend.latest', 'IMPROVED')
        ->assertJsonPath('meta.trend.direction', 1);

    expect($response->json('meta.metrics.0.name'))->toBe('Pain')
        ->and($response->json('meta.metrics.0.unit'))->toBe('/10')
        ->and(array_column($response->json('meta.metrics.0.points'), 'date'))->toBe(['2026-10-01', '2026-10-15', '2026-10-29']);
});

test('a declining patient shows a downward trend', function () {
    $worker = ClinicUser::factory()->worker()->create();
    $patient = Patient::factory()->for($worker->clinic)->create();
    PatientProgressNote::factory()->on($patient, '2026-10-01')->by($worker)->rated(ProgressRating::Baseline)->create();
    PatientProgressNote::factory()->on($patient, '2026-10-20')->by($worker)->rated(ProgressRating::Declined)->create();
    Sanctum::actingAs($worker->user);

    $this->getJson(route('clinic.patients.progress-notes.index', [$worker->clinic_id, $patient]))
        ->assertOk()
        ->assertJsonPath('meta.trend.declined', 1)
        ->assertJsonPath('meta.trend.latest', 'DECLINED')
        ->assertJsonPath('meta.trend.direction', -1);
});

test('updating a note replaces its measurements', function () {
    $worker = ClinicUser::factory()->worker()->create();
    $patient = Patient::factory()->for($worker->clinic)->create();
    $note = PatientProgressNote::factory()->on($patient, '2026-10-01')->by($worker)->create();
    PatientProgressMetric::factory()->for($note, 'patientProgressNote')->create(['name' => 'Pain', 'value' => 8]);
    Sanctum::actingAs($worker->user);

    $this->patchJson(route('clinic.patients.progress-notes.update', [$worker->clinic_id, $patient, $note]), [
        'progress' => 'IMPROVED',
        'metrics' => [['name' => 'Pain', 'value' => 4, 'unit' => '/10']],
    ])->assertOk()
        ->assertJsonPath('data.progress', 'IMPROVED')
        ->assertJsonCount(1, 'data.metrics')
        ->assertJsonPath('data.metrics.0.value', '4.00');

    $this->assertDatabaseCount('patient_progress_metrics', 1);
});

test('only the author or a Clinic Admin may change a note', function () {
    $author = ClinicUser::factory()->worker()->create();
    $colleague = ClinicUser::factory()->worker()->create(['clinic_id' => $author->clinic_id]);
    $admin = ClinicUser::factory()->admin()->create(['clinic_id' => $author->clinic_id]);
    $patient = Patient::factory()->for($author->clinic)->create();
    $note = PatientProgressNote::factory()->on($patient, '2026-10-01')->by($author)->create();

    Sanctum::actingAs($colleague->user);
    $this->patchJson(route('clinic.patients.progress-notes.update', [$author->clinic_id, $patient, $note]), ['summary' => 'Mine now'])
        ->assertForbidden();

    Sanctum::actingAs($author->user);
    $this->patchJson(route('clinic.patients.progress-notes.update', [$author->clinic_id, $patient, $note]), ['summary' => 'Corrected'])
        ->assertOk();

    Sanctum::actingAs($admin->user);
    $this->deleteJson(route('clinic.patients.progress-notes.destroy', [$author->clinic_id, $patient, $note]))->assertNoContent();
    $this->assertSoftDeleted($note);
});

test('a removed note drops out of the history but stays in the database', function () {
    $worker = ClinicUser::factory()->worker()->create();
    $patient = Patient::factory()->for($worker->clinic)->create();
    $note = PatientProgressNote::factory()->on($patient, '2026-10-01')->by($worker)->create();
    Sanctum::actingAs($worker->user);

    $this->deleteJson(route('clinic.patients.progress-notes.destroy', [$worker->clinic_id, $patient, $note]))->assertNoContent();

    $this->getJson(route('clinic.patients.progress-notes.index', [$worker->clinic_id, $patient]))
        ->assertOk()
        ->assertJsonCount(0, 'data')
        ->assertJsonPath('meta.trend.notes', 0);

    $this->assertDatabaseCount('patient_progress_notes', 1);
});

test('the clinic-wide list is newest first and filters by patient, author and progress', function () {
    $worker = ClinicUser::factory()->worker()->create();
    $colleague = ClinicUser::factory()->worker()->create(['clinic_id' => $worker->clinic_id]);
    $ana = Patient::factory()->for($worker->clinic)->create(['first_name' => 'Ana', 'last_name' => 'Reyes']);
    $bea = Patient::factory()->for($worker->clinic)->create(['first_name' => 'Bea', 'last_name' => 'Cruz']);
    PatientProgressNote::factory()->on($ana, '2026-10-01')->by($worker)->rated(ProgressRating::Declined)->create();
    PatientProgressNote::factory()->on($bea, '2026-10-20')->by($colleague)->rated(ProgressRating::Improved)->create();
    Sanctum::actingAs($worker->user);

    $this->getJson(route('clinic.progress-notes.index', $worker->clinic_id))
        ->assertOk()
        ->assertJsonCount(2, 'data')
        ->assertJsonPath('data.0.recorded_on', '2026-10-20');

    $this->getJson(route('clinic.progress-notes.index', [$worker->clinic_id, 'progress' => 'DECLINED']))
        ->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.patient_id', $ana->id);

    $this->getJson(route('clinic.progress-notes.index', [$worker->clinic_id, 'clinic_user_id' => $colleague->id]))
        ->assertOk()
        ->assertJsonCount(1, 'data');

    $this->getJson(route('clinic.progress-notes.index', [$worker->clinic_id, 'search' => 'Reyes']))
        ->assertOk()
        ->assertJsonCount(1, 'data');
});

test('a note needs a date, a rating and a summary', function () {
    $worker = ClinicUser::factory()->worker()->create();
    $patient = Patient::factory()->for($worker->clinic)->create();
    Sanctum::actingAs($worker->user);

    $this->postJson(route('clinic.patients.progress-notes.store', [$worker->clinic_id, $patient]), [])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['recorded_on', 'progress', 'summary']);
});

test('progress notes of another clinic are out of reach', function () {
    $foreignWorker = ClinicUser::factory()->worker()->create();
    $foreignPatient = Patient::factory()->for($foreignWorker->clinic)->create();
    PatientProgressNote::factory()->on($foreignPatient, '2026-10-01')->by($foreignWorker)->create();
    $worker = ClinicUser::factory()->worker()->create();
    Sanctum::actingAs($worker->user);

    $this->getJson(route('clinic.progress-notes.index', $worker->clinic_id))->assertOk()->assertJsonCount(0, 'data');
    $this->getJson(route('clinic.patients.progress-notes.index', [$worker->clinic_id, $foreignPatient]))->assertNotFound();
});

test('a role without the progress note permissions cannot read or write them', function () {
    $role = ClinicRole::factory()->withPermissions(['clinic.patients.index', 'clinic.patients.show'])->create();
    $member = ClinicUser::factory()->withRole($role)->create();
    $patient = Patient::factory()->for($member->clinic)->create();
    Sanctum::actingAs($member->user);

    $this->getJson(route('clinic.progress-notes.index', $role->clinic_id))->assertForbidden();
    $this->getJson(route('clinic.patients.progress-notes.index', [$role->clinic_id, $patient]))->assertForbidden();
    $this->postJson(route('clinic.patients.progress-notes.store', [$role->clinic_id, $patient]), [])->assertForbidden();
});
