<?php

namespace App\Http\Controllers;

use App\Actions\Patients\SubmitPatientForm;
use App\Http\Resources\PatientFormFieldResource;
use App\Models\PatientFormInvitation;
use App\Support\PatientFormSchema;
use BackedEnum;
use DateTimeInterface;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The page a patient intake link opens. No sign-in: the token is the proof,
 * it works once, and only its hash is stored.
 *
 * This is deliberately outside /admin — the person filling it in is a
 * patient, not a member of staff.
 */
class PatientIntakeController extends Controller
{
    public function __construct(private PatientFormSchema $schema) {}

    public function show(string $token): Response
    {
        $invitation = PatientFormInvitation::findByToken($token)?->loadMissing(['clinic', 'patient']);

        if ($invitation === null || $invitation->clinic === null) {
            return $this->page('invalid');
        }

        $status = $invitation->status();

        if ($status !== 'pending') {
            return $this->page($status, $invitation);
        }

        return $this->page('open', $invitation, [
            'form' => $this->formFor($invitation),
            'answers' => $this->answersFor($invitation),
        ]);
    }

    /**
     * Save the patient's own answers and burn the link.
     */
    public function submit(Request $request, string $token, SubmitPatientForm $submit): RedirectResponse
    {
        $invitation = PatientFormInvitation::findByToken($token);

        if ($invitation === null || $invitation->status() !== 'pending') {
            return to_route('patient-form.show', $token);
        }

        $clinic = $invitation->clinic;
        $fields = $this->schema->fieldsFor($clinic);

        $data = $request->validate(
            $this->schema->rules($clinic, creating: $invitation->patient === null, fields: $fields),
            [],
            $this->schema->attributes($clinic, $fields),
        );

        $submit->handle($invitation, $data);

        return to_route('patient-form.show', $token);
    }

    /**
     * The clinic's form, as the public page renders it.
     *
     * @return array<string, mixed>
     */
    private function formFor(PatientFormInvitation $invitation): array
    {
        $fields = $this->schema->fieldsFor($invitation->clinic);

        return [
            'sections' => $fields
                ->groupBy('section')
                ->map(fn ($sectionFields, string $section): array => [
                    'name' => $section,
                    'fields' => PatientFormFieldResource::collection($sectionFields->values())->resolve(),
                ])
                ->values()
                ->all(),
        ];
    }

    /**
     * What the record already says, when the link updates an existing
     * patient, so they correct rather than retype.
     *
     * @return array<string, mixed>
     */
    private function answersFor(PatientFormInvitation $invitation): array
    {
        $patient = $invitation->patient;

        if ($patient === null) {
            return [];
        }

        $patient->loadMissing('patientFieldValues.patientFormField');
        $answers = [];

        foreach ($this->schema->fieldsFor($invitation->clinic) as $field) {
            $answers[$field->key] = $field->isBuiltIn()
                ? $this->builtInValue($patient->getAttribute($field->patient_column))
                : $patient->patientFieldValues
                    ->firstWhere('patient_form_field_id', $field->id)
                    ?->typedValue();
        }

        return $answers;
    }

    /**
     * A built-in column can be cast to a date or an enum; the form wants the
     * plain value it will send back.
     */
    private function builtInValue(mixed $value): mixed
    {
        return match (true) {
            $value instanceof DateTimeInterface => $value->format('Y-m-d'),
            $value instanceof BackedEnum => $value->value,
            default => $value,
        };
    }

    /**
     * @param  array<string, mixed>  $extra
     */
    private function page(string $state, ?PatientFormInvitation $invitation = null, array $extra = []): Response
    {
        return Inertia::render('public/PatientForm', [
            'state' => $state,
            'token' => $invitation !== null ? request()->route('token') : null,
            'clinic' => $invitation === null ? null : [
                'name' => $invitation->clinic?->name,
            ],
            'invitation' => $invitation === null ? null : [
                'name' => $invitation->name,
                'email' => $invitation->email,
                'is_update' => $invitation->patient_id !== null,
                'expires_at' => $invitation->expires_at,
                'submitted_at' => $invitation->submitted_at,
            ],
            ...$extra,
        ]);
    }
}
