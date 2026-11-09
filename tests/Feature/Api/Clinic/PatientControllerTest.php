<?php

use App\Enums\PatientFormFieldType;
use App\Models\ClinicUser;
use App\Models\Patient;
use App\Models\PatientFormField;
use Laravel\Sanctum\Sanctum;

test('a worker creates a patient in their clinic', function () {
    $worker = ClinicUser::factory()->worker()->create();
    Sanctum::actingAs($worker->user);

    $this->postJson(route('clinic.patients.store', $worker->clinic_id), [
        'first_name' => 'Ana',
        'last_name' => 'Reyes',
        'sex' => 'female',
        'birth_date' => '1990-05-01',
    ])->assertCreated()->assertJsonPath('data.last_name', 'Reyes');

    $this->assertDatabaseHas('patients', ['clinic_id' => $worker->clinic_id, 'first_name' => 'Ana', 'sex' => 'female']);
});

test('creating a patient requires a first and last name', function () {
    $worker = ClinicUser::factory()->worker()->create();
    Sanctum::actingAs($worker->user);

    $this->postJson(route('clinic.patients.store', $worker->clinic_id), [])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['first_name', 'last_name']);
});

test('the patient list is limited to the clinic and filtered by search', function () {
    $worker = ClinicUser::factory()->worker()->create();
    Patient::factory()->for($worker->clinic)->create(['last_name' => 'Santos']);
    Patient::factory()->for($worker->clinic)->create(['last_name' => 'Cruz']);
    Patient::factory()->create(['last_name' => 'Santos']);
    Sanctum::actingAs($worker->user);

    $this->getJson(route('clinic.patients.index', [$worker->clinic_id, 'search' => 'Santos']))
        ->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.last_name', 'Santos');
});

test('updating a patient changes only the sent fields', function () {
    $worker = ClinicUser::factory()->worker()->create();
    $patient = Patient::factory()->for($worker->clinic)->create(['first_name' => 'Ana', 'last_name' => 'Reyes']);
    Sanctum::actingAs($worker->user);

    $this->patchJson(route('clinic.patients.update', [$worker->clinic_id, $patient]), ['phone' => '0917 000 0000'])
        ->assertOk()
        ->assertJsonPath('data.first_name', 'Ana')
        ->assertJsonPath('data.phone', '0917 000 0000');
});

test('deleting a patient archives it', function () {
    $admin = ClinicUser::factory()->admin()->create();
    $patient = Patient::factory()->for($admin->clinic)->create();
    Sanctum::actingAs($admin->user);

    $this->deleteJson(route('clinic.patients.destroy', [$admin->clinic_id, $patient]))->assertNoContent();

    $this->assertSoftDeleted($patient);
    $this->getJson(route('clinic.patients.show', [$admin->clinic_id, $patient]))->assertNotFound();
});

test('a patient carries the answers to the clinic own form fields', function () {
    $admin = ClinicUser::factory()->admin()->create();
    PatientFormField::factory()->for($admin->clinic)->create(['key' => 'allergies', 'label' => 'Allergies']);
    PatientFormField::factory()->for($admin->clinic)
        ->type(PatientFormFieldType::Multiselect, ['physio', 'speech'])
        ->create(['key' => 'services_wanted']);
    Sanctum::actingAs($admin->user);

    $id = $this->postJson(route('clinic.patients.store', $admin->clinic_id), [
        'first_name' => 'Ana',
        'last_name' => 'Reyes',
        'allergies' => 'Penicillin',
        'services_wanted' => ['physio'],
    ])->assertCreated()
        ->assertJsonPath('data.fields.allergies', 'Penicillin')
        ->assertJsonPath('data.fields.services_wanted', ['physio'])
        ->json('data.id');

    $this->getJson(route('clinic.patients.show', [$admin->clinic_id, $id]))
        ->assertOk()
        ->assertJsonPath('data.fields.allergies', 'Penicillin');

    // Blanking an answer clears it rather than storing an empty string.
    $this->patchJson(route('clinic.patients.update', [$admin->clinic_id, $id]), ['allergies' => ''])->assertOk();
    $this->assertDatabaseCount('patient_field_values', 1);
});

test('clinic_id can never be set through the patient form', function () {
    $admin = ClinicUser::factory()->admin()->create();
    $otherClinic = ClinicUser::factory()->admin()->create()->clinic_id;
    Sanctum::actingAs($admin->user);

    $id = $this->postJson(route('clinic.patients.store', $admin->clinic_id), [
        'first_name' => 'Ana',
        'last_name' => 'Reyes',
        'clinic_id' => $otherClinic,
    ])->assertCreated()->json('data.id');

    expect(Patient::query()->findOrFail($id)->clinic_id)->toBe($admin->clinic_id);

    $this->patchJson(route('clinic.patients.update', [$admin->clinic_id, $id]), ['clinic_id' => $otherClinic])->assertOk();

    expect(Patient::query()->findOrFail($id)->clinic_id)->toBe($admin->clinic_id);
});

test('a required custom field is required, and a removed one is no longer asked for', function () {
    $admin = ClinicUser::factory()->admin()->create();
    $field = PatientFormField::factory()->for($admin->clinic)->required()->create(['key' => 'consent']);
    Sanctum::actingAs($admin->user);

    $this->postJson(route('clinic.patients.store', $admin->clinic_id), ['first_name' => 'Ana', 'last_name' => 'Reyes'])
        ->assertUnprocessable()
        ->assertJsonValidationErrors('consent');

    $field->delete();

    $this->postJson(route('clinic.patients.store', $admin->clinic_id), ['first_name' => 'Ana', 'last_name' => 'Reyes'])
        ->assertCreated();
});

test('answers to a removed field are kept and shown apart', function () {
    $admin = ClinicUser::factory()->admin()->create();
    $field = PatientFormField::factory()->for($admin->clinic)->create(['key' => 'allergies', 'label' => 'Allergies']);
    Sanctum::actingAs($admin->user);

    $id = $this->postJson(route('clinic.patients.store', $admin->clinic_id), [
        'first_name' => 'Ana',
        'last_name' => 'Reyes',
        'allergies' => 'Penicillin',
    ])->assertCreated()->json('data.id');

    $field->delete();

    $this->getJson(route('clinic.patients.show', [$admin->clinic_id, $id]))
        ->assertOk()
        ->assertJsonPath('data.fields', [])
        ->assertJsonPath('data.archived_fields.0.label', 'Allergies')
        ->assertJsonPath('data.archived_fields.0.value', 'Penicillin');
});

test('relabelling a built-in field does not change the column it writes to', function () {
    $admin = ClinicUser::factory()->admin()->create();
    $email = $admin->clinic->patientFormFields()->where('key', 'email')->sole();
    Sanctum::actingAs($admin->user);

    $this->patchJson(route('clinic.patient-form.fields.update', [$admin->clinic_id, $email]), ['label' => 'Email address'])->assertOk();

    $this->postJson(route('clinic.patients.store', $admin->clinic_id), [
        'first_name' => 'Ana',
        'last_name' => 'Reyes',
        'email' => 'ana@example.com',
    ])->assertCreated()->assertJsonPath('data.email', 'ana@example.com');

    $this->assertDatabaseHas('patients', ['email' => 'ana@example.com']);
});

test('a birth date in the future is still refused after the field is relabelled', function () {
    $admin = ClinicUser::factory()->admin()->create();
    $birthDate = $admin->clinic->patientFormFields()->where('key', 'birth_date')->sole();
    $birthDate->update(['label' => 'Birthday']);
    Sanctum::actingAs($admin->user);

    $this->postJson(route('clinic.patients.store', $admin->clinic_id), [
        'first_name' => 'Ana',
        'last_name' => 'Reyes',
        'birth_date' => now()->addYear()->toDateString(),
    ])->assertUnprocessable()->assertJsonValidationErrors('birth_date');
});
