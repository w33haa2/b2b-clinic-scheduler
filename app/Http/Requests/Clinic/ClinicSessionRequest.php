<?php

namespace App\Http\Requests\Clinic;

use App\Models\Clinic;
use App\Models\ClinicSession;
use App\Models\ClinicSessionMember;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Date;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class ClinicSessionRequest extends FormRequest
{
    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        $required = $this->isMethod('POST') ? 'required' : 'sometimes';
        $clinic = $this->clinic();

        return [
            'name' => ['nullable', 'string', 'max:255'],
            'starts_at' => [$required, 'date'],
            'ends_at' => [$required, 'date'],
            'clinic_user_id' => [$required, 'integer', Rule::exists('clinic_users', 'id')
                ->where('clinic_id', $clinic->id)
                ->whereNull('deleted_at')],
            'patient_ids' => [Rule::excludeIf(! $this->isMethod('POST')), 'array'],
            'patient_ids.*' => ['integer', 'distinct', Rule::exists('patients', 'id')
                ->where('clinic_id', $clinic->id)
                ->whereNull('deleted_at')],
        ];
    }

    /**
     * Get the "after" validation callables for the request.
     *
     * @return array<int, callable>
     */
    public function after(): array
    {
        return [
            function (Validator $validator): void {
                if ($validator->errors()->hasAny(['starts_at', 'ends_at', 'clinic_user_id'])) {
                    return;
                }

                $session = $this->clinicSession();
                $startsAt = $this->startsAt();
                $endsAt = $this->endsAt();

                if ($endsAt->lessThanOrEqualTo($startsAt)) {
                    $validator->errors()->add('ends_at', __('The session must end after it starts.'));

                    return;
                }

                $clinicUserId = (int) $this->input('clinic_user_id', $session?->clinic_user_id);

                $isDoubleBooked = ClinicSession::query()
                    ->where('clinic_user_id', $clinicUserId)
                    ->overlapping($startsAt, $endsAt)
                    ->when($session, fn ($query) => $query->whereKeyNot($session->id))
                    ->exists()
                    || ClinicSessionMember::query()->plottedOverlapping($clinicUserId, $startsAt, $endsAt)->exists();

                if ($isDoubleBooked) {
                    $validator->errors()->add('clinic_user_id', __('This member already has a session at that time.'));
                }
            },
        ];
    }

    /**
     * The validated session attributes with times converted to UTC.
     *
     * @return array<string, mixed>
     */
    public function sessionAttributes(): array
    {
        $attributes = $this->safe()->only(['name', 'clinic_user_id']);

        if ($this->has('starts_at')) {
            $attributes['starts_at'] = $this->startsAt();
        }

        if ($this->has('ends_at')) {
            $attributes['ends_at'] = $this->endsAt();
        }

        return $attributes;
    }

    private function startsAt(): CarbonImmutable
    {
        return $this->has('starts_at')
            ? Date::parse($this->string('starts_at')->toString())->utc()
            : CarbonImmutable::instance($this->clinicSession()->starts_at);
    }

    private function endsAt(): CarbonImmutable
    {
        return $this->has('ends_at')
            ? Date::parse($this->string('ends_at')->toString())->utc()
            : CarbonImmutable::instance($this->clinicSession()->ends_at);
    }

    private function clinic(): Clinic
    {
        /** @var Clinic $clinic */
        $clinic = $this->route('clinic');

        return $clinic;
    }

    private function clinicSession(): ?ClinicSession
    {
        /** @var ClinicSession|null $session */
        $session = $this->route('clinicSession');

        return $session;
    }
}
