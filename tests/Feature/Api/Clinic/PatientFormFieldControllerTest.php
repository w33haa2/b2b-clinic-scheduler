<?php

use App\Enums\PatientFormFieldType;
use App\Models\ClinicRole;
use App\Models\ClinicUser;
use App\Models\Patient;
use App\Models\PatientFieldValue;
use App\Models\PatientFormField;
use Laravel\Sanctum\Sanctum;

/**
 * A custom field on the clinic's form, with the given number of answers
 * already given to it.
 */
function answeredField(ClinicUser $member, int $answers = 0, PatientFormFieldType $type = PatientFormFieldType::Text): PatientFormField
{
    $field = PatientFormField::factory()->for($member->clinic)->type($type)->create(['key' => 'allergies', 'label' => 'Allergies']);

    for ($i = 0; $i < $answers; $i++) {
        PatientFieldValue::factory()->create([
            'patient_form_field_id' => $field->id,
            'patient_id' => Patient::factory()->for($member->clinic)->create()->id,
            'value' => 'Penicillin',
        ]);
    }

    return $field;
}

test('every clinic starts with the nine built-in fields, in order', function () {
    $admin = ClinicUser::factory()->admin()->create();
    Sanctum::actingAs($admin->user);

    $response = $this->getJson(route('clinic.patient-form.fields.index', $admin->clinic_id))
        ->assertOk()
        ->assertJsonCount(9, 'data')
        ->assertJsonPath('data.0.key', 'first_name')
        ->assertJsonPath('data.0.is_built_in', true)
        ->assertJsonPath('data.0.is_required', true)
        ->assertJsonPath('data.4.key', 'sex')
        ->assertJsonPath('data.4.type', 'SELECT')
        ->assertJsonPath('data.4.options', ['male', 'female', 'other']);

    expect(collect($response->json('data'))->pluck('key')->all())
        ->toBe(['first_name', 'middle_name', 'last_name', 'birth_date', 'sex', 'email', 'phone', 'address', 'notes']);
});

test('an admin adds a custom field and the patient API starts accepting it', function () {
    $admin = ClinicUser::factory()->admin()->create();
    Sanctum::actingAs($admin->user);

    $this->postJson(route('clinic.patient-form.fields.store', $admin->clinic_id), [
        'label' => 'Blood type',
        'type' => 'SELECT',
        'options' => ['A+', 'O-'],
        'section' => 'Medical',
        'is_required' => true,
    ])->assertCreated()
        ->assertJsonPath('data.key', 'blood_type')
        ->assertJsonPath('data.is_built_in', false);

    $this->postJson(route('clinic.patients.store', $admin->clinic_id), [
        'first_name' => 'Ana',
        'last_name' => 'Reyes',
    ])->assertUnprocessable()->assertJsonValidationErrors('blood_type');

    $this->postJson(route('clinic.patients.store', $admin->clinic_id), [
        'first_name' => 'Ana',
        'last_name' => 'Reyes',
        'blood_type' => 'O-',
    ])->assertCreated()->assertJsonPath('data.fields.blood_type', 'O-');
});

test('a built-in field can be relabelled but never retyped, rekeyed or removed', function () {
    $admin = ClinicUser::factory()->admin()->create();
    $birthDate = $admin->clinic->patientFormFields()->where('key', 'birth_date')->sole();
    Sanctum::actingAs($admin->user);

    $this->patchJson(route('clinic.patient-form.fields.update', [$admin->clinic_id, $birthDate]), [
        'label' => 'Birthday',
        'help_text' => 'Day, month and year.',
    ])->assertOk()->assertJsonPath('data.label', 'Birthday');

    $this->patchJson(route('clinic.patient-form.fields.update', [$admin->clinic_id, $birthDate]), ['type' => 'TEXT'])
        ->assertUnprocessable()
        ->assertJsonValidationErrors('patient_form_field_type_id');

    $this->patchJson(route('clinic.patient-form.fields.update', [$admin->clinic_id, $birthDate]), ['key' => 'dob'])
        ->assertUnprocessable()
        ->assertJsonValidationErrors('key');

    $this->deleteJson(route('clinic.patient-form.fields.destroy', [$admin->clinic_id, $birthDate]))
        ->assertUnprocessable()
        ->assertJsonValidationErrors('patient_form_field');

    expect($birthDate->refresh()->patient_column)->toBe('birth_date')
        ->and($birthDate->patient_form_field_type_id)->toBe(PatientFormFieldType::Date);
});

test('the two names the database insists on cannot be made optional', function (string $key) {
    $admin = ClinicUser::factory()->admin()->create();
    $field = $admin->clinic->patientFormFields()->where('key', $key)->sole();
    Sanctum::actingAs($admin->user);

    $this->patchJson(route('clinic.patient-form.fields.update', [$admin->clinic_id, $field]), ['is_required' => false])
        ->assertUnprocessable()
        ->assertJsonValidationErrors('is_required');
})->with(['first_name', 'last_name']);

test('removing an answered field needs confirming, and keeps the answers', function () {
    $admin = ClinicUser::factory()->admin()->create();
    $field = answeredField($admin, answers: 2);
    Sanctum::actingAs($admin->user);

    $this->getJson(route('clinic.patient-form.fields.usage', [$admin->clinic_id, $field]))
        ->assertOk()
        ->assertJsonPath('data.answer_count', 2)
        ->assertJsonPath('data.can_be_removed', true);

    $this->deleteJson(route('clinic.patient-form.fields.destroy', [$admin->clinic_id, $field]))
        ->assertUnprocessable()
        ->assertJsonValidationErrors('patient_form_field');

    $this->deleteJson(route('clinic.patient-form.fields.destroy', [$admin->clinic_id, $field, 'confirm' => 1]))
        ->assertNoContent();

    $this->assertSoftDeleted($field);
    $this->assertDatabaseCount('patient_field_values', 2);
});

test('an unanswered field is removed without a prompt', function () {
    $admin = ClinicUser::factory()->admin()->create();
    $field = answeredField($admin);
    Sanctum::actingAs($admin->user);

    $this->deleteJson(route('clinic.patient-form.fields.destroy', [$admin->clinic_id, $field]))->assertNoContent();

    $this->assertSoftDeleted($field);
});

test('a removed field is listed under archived and can be put back with its answers', function () {
    $admin = ClinicUser::factory()->admin()->create();
    $field = answeredField($admin, answers: 1);
    $field->delete();
    Sanctum::actingAs($admin->user);

    $this->getJson(route('clinic.patient-form.fields.index', [$admin->clinic_id, 'state' => 'active']))
        ->assertOk()
        ->assertJsonCount(9, 'data');

    $this->getJson(route('clinic.patient-form.fields.index', [$admin->clinic_id, 'state' => 'archived', 'with_usage' => 1]))
        ->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.is_archived', true)
        ->assertJsonPath('data.0.answer_count', 1);

    $this->postJson(route('clinic.patient-form.fields.restore', [$admin->clinic_id, $field->id]))
        ->assertOk()
        ->assertJsonPath('data.is_archived', false);

    expect($field->refresh()->trashed())->toBeFalse()
        ->and($field->patientFieldValues()->count())->toBe(1);
});

test('a removed field keeps its key, and adding it again points at Restore', function () {
    $admin = ClinicUser::factory()->admin()->create();
    $field = answeredField($admin, answers: 1);
    $field->delete();
    Sanctum::actingAs($admin->user);

    $this->postJson(route('clinic.patient-form.fields.store', $admin->clinic_id), [
        'key' => 'allergies',
        'label' => 'Allergy notes',
        'type' => 'TEXT',
    ])->assertUnprocessable()
        ->assertJsonValidationErrors(['key' => '"Allergies" was removed from the form but still holds that key, and the answers given to it. Restore it from the Removed tab, or choose another key.']);

    // Restoring is the way back, and it brings the answers with it.
    $this->postJson(route('clinic.patient-form.fields.restore', [$admin->clinic_id, $field->id]))->assertOk();

    expect($field->refresh()->trashed())->toBeFalse()
        ->and($field->patientFieldValues()->count())->toBe(1);
});

test('renaming the key of an answered field needs confirming', function () {
    $admin = ClinicUser::factory()->admin()->create();
    $field = answeredField($admin, answers: 1);
    Sanctum::actingAs($admin->user);

    $this->patchJson(route('clinic.patient-form.fields.update', [$admin->clinic_id, $field]), ['key' => 'known_allergies'])
        ->assertUnprocessable()
        ->assertJsonValidationErrors('key');

    $this->patchJson(route('clinic.patient-form.fields.update', [$admin->clinic_id, $field]), [
        'key' => 'known_allergies',
        'confirm' => true,
    ])->assertOk()->assertJsonPath('data.key', 'known_allergies');

    // The answers follow the row, so nothing is orphaned.
    expect($field->refresh()->patientFieldValues()->count())->toBe(1);
});

test('a type change the existing answers cannot survive is refused outright', function () {
    $admin = ClinicUser::factory()->admin()->create();
    $field = answeredField($admin, answers: 1);
    Sanctum::actingAs($admin->user);

    $this->patchJson(route('clinic.patient-form.fields.update', [$admin->clinic_id, $field]), [
        'type' => 'NUMBER',
        'confirm' => true,
    ])->assertUnprocessable()
        ->assertJsonValidationErrors('patient_form_field_type_id');

    expect($field->refresh()->patient_form_field_type_id)->toBe(PatientFormFieldType::Text);
});

test('widening an answered field to long text only needs confirming', function () {
    $admin = ClinicUser::factory()->admin()->create();
    $field = answeredField($admin, answers: 1);
    Sanctum::actingAs($admin->user);

    $this->patchJson(route('clinic.patient-form.fields.update', [$admin->clinic_id, $field]), ['type' => 'TEXTAREA'])
        ->assertUnprocessable()
        ->assertJsonValidationErrors('patient_form_field_type_id');

    $this->patchJson(route('clinic.patient-form.fields.update', [$admin->clinic_id, $field]), [
        'type' => 'TEXTAREA',
        'confirm' => true,
    ])->assertOk()->assertJsonPath('data.type', 'TEXTAREA');
});

test('keys are unique, well formed and never collide with a reserved name', function (array $payload, string $field) {
    $admin = ClinicUser::factory()->admin()->create();
    Sanctum::actingAs($admin->user);

    $this->postJson(route('clinic.patient-form.fields.store', $admin->clinic_id), [
        'label' => 'Something',
        'type' => 'TEXT',
        ...$payload,
    ])->assertUnprocessable()->assertJsonValidationErrors($field);
})->with([
    'already taken by a built-in' => [['key' => 'first_name'], 'key'],
    'starts with a digit' => [['key' => '1st_visit'], 'key'],
    'has spaces' => [['key' => 'blood type'], 'key'],
    'collides with the resource' => [['key' => 'clinic_id'], 'key'],
    'collides with a form helper' => [['key' => 'errors'], 'key'],
    'a choice field with one option' => [['type' => 'SELECT', 'options' => ['only']], 'options'],
]);

test('the form can be rearranged and regrouped in one request', function () {
    $admin = ClinicUser::factory()->admin()->create();
    $fields = $admin->clinic->patientFormFields()->inFormOrder()->get();
    Sanctum::actingAs($admin->user);

    $this->putJson(route('clinic.patient-form.reorder', $admin->clinic_id), [
        'order' => $fields->reverse()->values()->map(fn (PatientFormField $field, int $index): array => [
            'id' => $field->id,
            'position' => $index,
            'section' => 'Everything',
        ])->all(),
    ])->assertOk()->assertJsonPath('data.sections.0.name', 'Everything');

    $this->getJson(route('clinic.patient-form.fields.index', $admin->clinic_id))
        ->assertOk()
        ->assertJsonPath('data.0.key', 'notes')
        ->assertJsonPath('data.8.key', 'first_name');

    $this->assertDatabaseHas('activity_logs', ['action' => 'patient_form.reordered', 'clinic_id' => $admin->clinic_id]);
});

test('the form definition comes back grouped into its sections', function () {
    $admin = ClinicUser::factory()->admin()->create();
    Sanctum::actingAs($admin->user);

    $this->getJson(route('clinic.patient-form.show', $admin->clinic_id))
        ->assertOk()
        ->assertJsonPath('data.sections.0.name', 'Personal details')
        ->assertJsonCount(5, 'data.sections.0.fields')
        ->assertJsonPath('data.sections.1.name', 'Contact')
        ->assertJsonCount(9, 'data.fields');
});

test('changes to the form are written to the activity log', function () {
    $admin = ClinicUser::factory()->admin()->create();
    Sanctum::actingAs($admin->user);

    $id = $this->postJson(route('clinic.patient-form.fields.store', $admin->clinic_id), [
        'label' => 'Referred by',
        'type' => 'TEXT',
    ])->json('data.id');

    $this->patchJson(route('clinic.patient-form.fields.update', [$admin->clinic_id, $id]), ['label' => 'Referral source'])->assertOk();
    $this->deleteJson(route('clinic.patient-form.fields.destroy', [$admin->clinic_id, $id]))->assertNoContent();

    $this->assertDatabaseHas('activity_logs', ['action' => 'patient_form_field.created', 'subject_label' => 'Referred by']);
    $this->assertDatabaseHas('activity_logs', ['action' => 'patient_form_field.updated', 'subject_label' => 'Referral source']);
    $this->assertDatabaseHas('activity_logs', ['action' => 'patient_form_field.removed', 'subject_label' => 'Referral source']);
});

test('the form of another clinic is out of reach', function () {
    $foreign = PatientFormField::factory()->create();
    $admin = ClinicUser::factory()->admin()->create();
    Sanctum::actingAs($admin->user);

    $this->getJson(route('clinic.patient-form.fields.show', [$admin->clinic_id, $foreign]))->assertNotFound();
    $this->patchJson(route('clinic.patient-form.fields.update', [$admin->clinic_id, $foreign]), ['label' => 'Mine'])->assertNotFound();
    $this->deleteJson(route('clinic.patient-form.fields.destroy', [$admin->clinic_id, $foreign]))->assertNotFound();
});

test('a clinic worker can use the form but not build it', function () {
    $worker = ClinicUser::factory()->worker()->create();
    Sanctum::actingAs($worker->user);

    $this->getJson(route('clinic.patient-form.show', $worker->clinic_id))->assertOk();
    $this->getJson(route('clinic.patient-form.fields.index', $worker->clinic_id))->assertForbidden();
    $this->postJson(route('clinic.patient-form.fields.store', $worker->clinic_id), [])->assertForbidden();
    $this->putJson(route('clinic.patient-form.reorder', $worker->clinic_id), [])->assertForbidden();
});

test('a role granted the builder permission can build the form', function () {
    $role = ClinicRole::factory()->withPermissions([
        'clinic.patient-form.show',
        'clinic.patient-form.fields.index',
        'clinic.patient-form.fields.store',
    ])->create();
    $member = ClinicUser::factory()->withRole($role)->create();
    Sanctum::actingAs($member->user);

    $this->getJson(route('clinic.patient-form.fields.index', $role->clinic_id))->assertOk();
    $this->postJson(route('clinic.patient-form.fields.store', $role->clinic_id), [
        'label' => 'Occupation',
        'type' => 'TEXT',
    ])->assertCreated();
});
