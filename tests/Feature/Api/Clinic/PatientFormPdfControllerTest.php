<?php

use App\Enums\PatientFormFieldType;
use App\Models\ClinicRole;
use App\Models\ClinicUser;
use App\Models\Patient;
use App\Models\PatientFormField;
use Laravel\Sanctum\Sanctum;

test('the blank form prints with every field the clinic asks for', function () {
    $admin = ClinicUser::factory()->admin()->create();
    PatientFormField::factory()->for($admin->clinic)->create(['key' => 'allergies', 'label' => 'Allergies']);
    Sanctum::actingAs($admin->user);

    $response = $this->get(route('clinic.patient-form.pdf', $admin->clinic_id))
        ->assertOk()
        ->assertHeader('content-type', 'application/pdf')
        ->assertHeader('content-disposition', 'attachment; filename="patient-form.pdf"');

    expect($response->getContent())->toStartWith('%PDF');
});

test('a patient record prints, whatever their built-in fields are cast to', function () {
    $admin = ClinicUser::factory()->admin()->create();
    PatientFormField::factory()->for($admin->clinic)
        ->type(PatientFormFieldType::Multiselect, ['physio', 'speech'])
        ->create(['key' => 'services_wanted']);
    Sanctum::actingAs($admin->user);

    // sex is cast to an enum and birth_date to a date, so both have to be
    // turned into something printable.
    $id = $this->postJson(route('clinic.patients.store', $admin->clinic_id), [
        'first_name' => 'Ana',
        'last_name' => 'Reyes',
        'sex' => 'female',
        'birth_date' => '1990-05-01',
        'services_wanted' => ['physio', 'speech'],
    ])->assertCreated()->json('data.id');

    $response = $this->get(route('clinic.patients.pdf', [$admin->clinic_id, $id]))
        ->assertOk()
        ->assertHeader('content-type', 'application/pdf');

    expect($response->getContent())->toStartWith('%PDF');
    $this->assertDatabaseHas('activity_logs', ['action' => 'patient.downloaded', 'subject_label' => 'Ana Reyes']);
});

test('printing needs the permission of the thing being printed', function () {
    $role = ClinicRole::factory()->withPermissions(['clinic.patients.index'])->create();
    $member = ClinicUser::factory()->withRole($role)->create();
    $patient = Patient::factory()->for($member->clinic)->create();
    Sanctum::actingAs($member->user);

    $this->get(route('clinic.patient-form.pdf', $role->clinic_id))->assertForbidden();
    $this->get(route('clinic.patients.pdf', [$role->clinic_id, $patient]))->assertForbidden();
});

test('a patient of another clinic cannot be printed', function () {
    $foreign = Patient::factory()->create();
    $admin = ClinicUser::factory()->admin()->create();
    Sanctum::actingAs($admin->user);

    $this->get(route('clinic.patients.pdf', [$admin->clinic_id, $foreign]))->assertNotFound();
});
