<?php

use App\Enums\PatientFormFieldType;
use App\Models\Clinic;
use App\Models\ClinicUser;
use App\Models\Patient;
use App\Models\PatientFormField;
use App\Models\PatientFormInvitation;
use Inertia\Testing\AssertableInertia as Assert;

/*
 * The intake link: a patient fills the clinic's form in themselves, with no
 * account. The token in the URL is the only proof, it works once, and only
 * its hash is stored.
 */

test('an open link shows the clinic form, grouped into its sections', function () {
    $clinic = Clinic::factory()->create(['name' => 'Demo Clinic']);
    PatientFormField::factory()->for($clinic)->create(['key' => 'allergies', 'label' => 'Allergies', 'section' => 'Medical']);
    PatientFormInvitation::factory()->for($clinic)->withToken('open-token')->create(['name' => 'Ana Reyes']);

    $this->get(route('patient-form.show', 'open-token'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('public/PatientForm')
            ->where('state', 'open')
            ->where('clinic.name', 'Demo Clinic')
            ->where('invitation.is_update', false)
            // Personal details, Contact, Notes, and the clinic's own Medical.
            ->has('form.sections', 4)
            ->where('form.sections.0.name', 'Personal details')
            ->where('form.sections.3.name', 'Medical'));
});

test('a patient fills the form in and the record is created', function () {
    $clinic = Clinic::factory()->create();
    PatientFormField::factory()->for($clinic)->create(['key' => 'allergies', 'label' => 'Allergies']);
    $invitation = PatientFormInvitation::factory()->for($clinic)->withToken('fill-me')->create();

    $this->post(route('patient-form.submit', 'fill-me'), [
        'first_name' => 'Ana',
        'last_name' => 'Reyes',
        'email' => 'ana@example.com',
        'allergies' => 'Penicillin',
    ])->assertRedirect(route('patient-form.show', 'fill-me'));

    $patient = Patient::query()->sole();

    expect($patient->clinic_id)->toBe($clinic->id)
        ->and($patient->first_name)->toBe('Ana')
        ->and($patient->patientFieldValues()->value('value'))->toBe('Penicillin')
        ->and($invitation->refresh()->status())->toBe('submitted')
        ->and($invitation->patient_id)->toBe($patient->id);
});

test('the link works once', function () {
    $clinic = Clinic::factory()->create();
    PatientFormInvitation::factory()->for($clinic)->withToken('once')->create();

    $this->post(route('patient-form.submit', 'once'), ['first_name' => 'Ana', 'last_name' => 'Reyes'])->assertRedirect();

    $this->post(route('patient-form.submit', 'once'), ['first_name' => 'Bea', 'last_name' => 'Cruz'])
        ->assertRedirect(route('patient-form.show', 'once'));

    expect(Patient::query()->count())->toBe(1);

    $this->get(route('patient-form.show', 'once'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page->where('state', 'submitted'));
});

test('a link aimed at a patient prefills their answers and updates the record', function () {
    $clinic = Clinic::factory()->create();
    $field = PatientFormField::factory()->for($clinic)->create(['key' => 'allergies', 'label' => 'Allergies']);
    $patient = Patient::factory()->for($clinic)->create(['first_name' => 'Ana', 'last_name' => 'Reyes', 'phone' => '0917']);
    $patient->patientFieldValues()->create(['patient_form_field_id' => $field->id, 'value' => 'Penicillin']);
    PatientFormInvitation::factory()->for($clinic)->withToken('update-me')->create(['patient_id' => $patient->id]);

    $this->get(route('patient-form.show', 'update-me'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('state', 'open')
            ->where('invitation.is_update', true)
            ->where('answers.first_name', 'Ana')
            ->where('answers.allergies', 'Penicillin'));

    $this->post(route('patient-form.submit', 'update-me'), ['phone' => '0918 111 1111'])->assertRedirect();

    expect(Patient::query()->count())->toBe(1)
        ->and($patient->refresh()->phone)->toBe('0918 111 1111')
        ->and($patient->first_name)->toBe('Ana');
});

test('the form is validated against the clinic own fields', function () {
    $clinic = Clinic::factory()->create();
    PatientFormField::factory()->for($clinic)->required()->create(['key' => 'consent', 'label' => 'Consent given']);
    PatientFormInvitation::factory()->for($clinic)->withToken('validate-me')->create();

    $this->post(route('patient-form.submit', 'validate-me'), ['first_name' => 'Ana'])
        ->assertSessionHasErrors(['last_name', 'consent']);

    expect(Patient::query()->count())->toBe(0);
});

test('a choice field only accepts its own options', function () {
    $clinic = Clinic::factory()->create();
    PatientFormField::factory()->for($clinic)->type(PatientFormFieldType::Select, ['O+', 'A-'])->create(['key' => 'blood_type']);
    PatientFormInvitation::factory()->for($clinic)->withToken('choices')->create();

    $this->post(route('patient-form.submit', 'choices'), [
        'first_name' => 'Ana',
        'last_name' => 'Reyes',
        'blood_type' => 'Z9',
    ])->assertSessionHasErrors('blood_type');
});

test('a used, cancelled, expired or unknown link joins nobody', function (string $state) {
    $clinic = Clinic::factory()->create();
    $token = 'token-'.$state;

    if ($state !== 'invalid') {
        $factory = PatientFormInvitation::factory()->for($clinic)->withToken($token);

        $factory = match ($state) {
            'submitted' => $factory->submitted(),
            'revoked' => $factory->revoked(),
            default => $factory->expired(),
        };

        $factory->create();
    }

    $this->get(route('patient-form.show', $token))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page->component('public/PatientForm')->where('state', $state));

    $this->post(route('patient-form.submit', $token), ['first_name' => 'Ana', 'last_name' => 'Reyes'])->assertRedirect();

    expect(Patient::query()->count())->toBe(0);
})->with(['submitted', 'revoked', 'expired', 'invalid']);

test('the intake page needs no sign-in and is rate limited', function () {
    $clinic = Clinic::factory()->create();
    PatientFormInvitation::factory()->for($clinic)->withToken('public-token')->create();

    $this->assertGuest();
    $this->get(route('patient-form.show', 'public-token'))->assertOk();

    expect(collect(app('router')->getRoutes()->getByName('patient-form.show')->gatherMiddleware()))
        ->toContain('throttle:invitations');
});

test('a patient filling the form in is attributed to nobody, but the clinic sees it happened', function () {
    $clinic = Clinic::factory()->create();
    ClinicUser::factory()->admin()->create(['clinic_id' => $clinic->id]);
    PatientFormInvitation::factory()->for($clinic)->withToken('logged')->create();

    $this->post(route('patient-form.submit', 'logged'), ['first_name' => 'Ana', 'last_name' => 'Reyes'])->assertRedirect();

    $this->assertDatabaseHas('activity_logs', [
        'action' => 'patient_form_invitation.submitted',
        'clinic_id' => $clinic->id,
        'user_id' => null,
        'subject_label' => 'Ana Reyes',
    ]);
    // The patient's own save is not logged as if a member had done it.
    $this->assertDatabaseMissing('activity_logs', ['action' => 'patient.created']);
});
