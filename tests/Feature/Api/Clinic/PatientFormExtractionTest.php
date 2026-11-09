<?php

use App\Models\ClinicUser;
use App\Models\PatientFormField;
use Illuminate\Http\UploadedFile;
use Laravel\Sanctum\Sanctum;

/**
 * An upload the wizard can read, written to a temporary file.
 */
function uploadedForm(string $name, string $contents): UploadedFile
{
    $path = tempnam(sys_get_temp_dir(), 'form').'-'.$name;
    file_put_contents($path, $contents);

    return new UploadedFile($path, $name, null, null, true);
}

test('a printed form pasted as text is read into suggested fields', function () {
    $admin = ClinicUser::factory()->admin()->create();
    Sanctum::actingAs($admin->user);

    $response = $this->postJson(route('clinic.patient-form.extract.store', $admin->clinic_id), [
        'text' => <<<'TEXT'
            PERSONAL DETAILS
            Last name: ______________
            Date of Birth: __________
            Sex:  ☐ Male  ☐ Female
            MEDICAL
            Do you have any allergies?
            Current medication: _____
            TEXT,
    ])->assertOk()->assertJsonPath('data.source', 'TEXT');

    $fields = collect($response->json('data.fields'))->keyBy('key');

    // A question keeps its wording as the label, so its key is the question.
    expect($fields->keys()->all())
        ->toContain('last_name', 'birth_date', 'sex', 'do_you_have_any_allergies', 'current_medication')
        ->and($fields['birth_date']['type'])->toBe('DATE')
        ->and($fields['sex']['options'])->toBe(['Male', 'Female'])
        ->and($fields['do_you_have_any_allergies']['label'])->toBe('Do you have any allergies')
        ->and($fields['do_you_have_any_allergies']['type'])->toBe('TEXTAREA')
        ->and($fields['current_medication']['type'])->toBe('TEXTAREA')
        ->and($fields['last_name']['section'])->toBe('Personal Details')
        ->and($fields['current_medication']['section'])->toBe('Medical');

    // Nothing is saved until the user confirms.
    expect($admin->clinic->patientFormFields()->count())->toBe(9);
});

test('suggestions say which fields the clinic already has', function () {
    $admin = ClinicUser::factory()->admin()->create();
    Sanctum::actingAs($admin->user);

    $response = $this->postJson(route('clinic.patient-form.extract.store', $admin->clinic_id), [
        'text' => "Last name: ____\nAllergies: ____\n",
    ])->assertOk();

    $fields = collect($response->json('data.fields'))->keyBy('key');

    expect($fields['last_name']['existing_field_id'])->not->toBeNull()
        ->and($fields['last_name']['existing_label'])->toBe('Last name')
        ->and($fields['allergies']['existing_field_id'])->toBeNull();
});

test('a spreadsheet header row becomes the fields, with repeated values as choices', function () {
    $admin = ClinicUser::factory()->admin()->create();
    Sanctum::actingAs($admin->user);

    $csv = "First Name,Email,Blood Type\nAna,ana@example.com,O+\nBea,bea@example.com,A-\nCy,cy@example.com,O+\nDee,dee@example.com,A-\n";

    $response = $this->post(route('clinic.patient-form.extract.store', $admin->clinic_id), [
        'file' => uploadedForm('patients.csv', $csv),
    ], ['Accept' => 'application/json'])->assertOk()->assertJsonPath('data.source', 'CSV');

    $fields = collect($response->json('data.fields'))->keyBy('key');

    expect($fields['first_name']['type'])->toBe('TEXT')
        ->and($fields['email']['type'])->toBe('EMAIL')
        ->and($fields['blood_type']['options'])->toBe(['O+', 'A-']);
});

test('an HTML form is read from its real inputs', function () {
    $admin = ClinicUser::factory()->admin()->create();
    Sanctum::actingAs($admin->user);

    $html = <<<'HTML'
        <form>
            <label for="occupation">Occupation</label>
            <input id="occupation" name="occupation" required>
            <label for="language">Preferred language</label>
            <select id="language"><option>English</option><option>Filipino</option></select>
            <label for="story">Reason for visit</label>
            <textarea id="story"></textarea>
        </form>
        HTML;

    $response = $this->post(route('clinic.patient-form.extract.store', $admin->clinic_id), [
        'file' => uploadedForm('intake.html', $html),
    ], ['Accept' => 'application/json'])->assertOk();

    $fields = collect($response->json('data.fields'))->keyBy('key');

    expect($fields['occupation']['is_required'])->toBeTrue()
        ->and($fields['preferred_language']['type'])->toBe('SELECT')
        ->and($fields['preferred_language']['options'])->toBe(['English', 'Filipino'])
        ->and($fields['reason_for_visit']['type'])->toBe('TEXTAREA');
});

test('a file with nothing readable in it says so instead of failing', function () {
    $admin = ClinicUser::factory()->admin()->create();
    Sanctum::actingAs($admin->user);

    $this->post(route('clinic.patient-form.extract.store', $admin->clinic_id), [
        'file' => uploadedForm('notes.txt', 'Thank you for visiting our clinic. We look forward to seeing you again soon.'),
    ], ['Accept' => 'application/json'])
        ->assertOk()
        ->assertJsonCount(0, 'data.fields')
        ->assertJsonPath('data.warning', 'No fields were recognised in that file. You can paste the text in the box below, or add the fields by hand.');
});

test('a file type the wizard cannot read is refused', function () {
    $admin = ClinicUser::factory()->admin()->create();
    Sanctum::actingAs($admin->user);

    $this->post(route('clinic.patient-form.extract.store', $admin->clinic_id), [
        'file' => uploadedForm('scan.png', 'not really a png'),
    ], ['Accept' => 'application/json'])->assertUnprocessable()->assertJsonValidationErrors('file');
});

test('the confirmed suggestions are added, skipping the ones already on the form', function () {
    $admin = ClinicUser::factory()->admin()->create();
    Sanctum::actingAs($admin->user);

    $this->postJson(route('clinic.patient-form.fields.bulk', $admin->clinic_id), [
        'fields' => [
            ['key' => 'allergies', 'label' => 'Allergies', 'type' => 'TEXTAREA', 'section' => 'Medical'],
            ['key' => 'blood_type', 'label' => 'Blood type', 'type' => 'SELECT', 'options' => ['O+', 'A-']],
            ['key' => 'last_name', 'label' => 'Surname', 'type' => 'TEXT'],
        ],
    ])->assertOk()->assertJsonCount(2, 'data');

    expect($admin->clinic->patientFormFields()->count())->toBe(11);
    // The built-in field kept its own label.
    expect($admin->clinic->patientFormFields()->where('key', 'last_name')->value('label'))->toBe('Last name');
});

test('adding only fields the form already has is refused', function () {
    $admin = ClinicUser::factory()->admin()->create();
    Sanctum::actingAs($admin->user);

    $this->postJson(route('clinic.patient-form.fields.bulk', $admin->clinic_id), [
        'fields' => [['key' => 'email', 'label' => 'Email', 'type' => 'EMAIL']],
    ])->assertUnprocessable()->assertJsonValidationErrors('fields');
});

test('extraction needs either a file or some text', function () {
    $admin = ClinicUser::factory()->admin()->create();
    Sanctum::actingAs($admin->user);

    $this->postJson(route('clinic.patient-form.extract.store', $admin->clinic_id), [])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['file', 'text']);
});

test('a worker cannot import a form', function () {
    $worker = ClinicUser::factory()->worker()->create();
    Sanctum::actingAs($worker->user);

    $this->postJson(route('clinic.patient-form.extract.store', $worker->clinic_id), ['text' => 'Name: ___'])->assertForbidden();
    $this->postJson(route('clinic.patient-form.fields.bulk', $worker->clinic_id), ['fields' => []])->assertForbidden();
});

test('suggestions never leak between clinics', function () {
    $admin = ClinicUser::factory()->admin()->create();
    PatientFormField::factory()->create(['key' => 'allergies', 'label' => 'Allergies elsewhere']);
    Sanctum::actingAs($admin->user);

    $response = $this->postJson(route('clinic.patient-form.extract.store', $admin->clinic_id), [
        'text' => 'Allergies: ____',
    ])->assertOk();

    expect($response->json('data.fields.0.existing_field_id'))->toBeNull();
});
