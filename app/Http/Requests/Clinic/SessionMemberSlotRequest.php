<?php

namespace App\Http\Requests\Clinic;

use App\Models\Clinic;
use App\Models\ClinicSession;
use App\Models\ClinicSessionMember;
use App\Support\HolderAvailability;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Date;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class SessionMemberSlotRequest extends FormRequest
{
    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        /** @var Clinic $clinic */
        $clinic = $this->route('clinic');

        return [
            'starts_at' => ['sometimes', 'nullable', 'date', 'required_with:ends_at'],
            'ends_at' => ['sometimes', 'nullable', 'date', 'required_with:starts_at'],
            'line_item_id' => ['sometimes', 'integer', Rule::exists('line_items', 'id')->where('clinic_id', $clinic->id)->whereNull('deleted_at')],
            'unit_price' => ['sometimes', 'nullable', 'numeric', 'min:0', 'max:9999999999.99', 'decimal:0,2'],
            'reset' => ['sometimes', 'boolean'],
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
                if ($validator->errors()->isNotEmpty()) {
                    return;
                }

                $day = $this->clinicSession();

                if (! $day->isDailyOccurrence()) {
                    $validator->errors()->add('starts_at', __('Only patients of a daily session can be plotted into a time slot.'));

                    return;
                }

                if (! $day->status_id->isOpen()) {
                    $validator->errors()->add('starts_at', __('Patients cannot be plotted on a :status day.', ['status' => $day->status_id->code()]));

                    return;
                }

                if (! $this->hasAny(['starts_at', 'line_item_id', 'reset'])) {
                    $validator->errors()->add('starts_at', __('Send a slot, a service or reset.'));

                    return;
                }

                $startsAt = $this->startsAt();
                $endsAt = $this->endsAt();

                if ($startsAt === null || $endsAt === null) {
                    return;
                }

                if ($endsAt->lessThanOrEqualTo($startsAt)) {
                    $validator->errors()->add('ends_at', __('The slot must end after it starts.'));

                    return;
                }

                if ($startsAt->lessThan($day->starts_at) || $endsAt->greaterThan($day->ends_at)) {
                    $validator->errors()->add('starts_at', __('The slot must be inside the daily session\'s hours.'));

                    return;
                }

                if (app(HolderAvailability::class)->isBusy($day->clinic_user_id, $startsAt, $endsAt, $this->sessionMember()->id)) {
                    $validator->errors()->add('starts_at', __('The staff member is not free at that time. Pick a vacant slot.'));
                }
            },
        ];
    }

    /**
     * The changes for UpdateDayPatient.
     *
     * @return array{slot?: array{0: CarbonImmutable|null, 1: CarbonImmutable|null}, line_item_id?: int, unit_price?: string|null, reset?: bool}
     */
    public function changes(): array
    {
        $changes = [];

        if ($this->boolean('reset')) {
            return ['reset' => true];
        }

        if ($this->has('starts_at')) {
            $changes['slot'] = [$this->startsAt(), $this->endsAt()];
        }

        if ($this->has('line_item_id')) {
            $changes['line_item_id'] = $this->integer('line_item_id');
            $changes['unit_price'] = $this->filled('unit_price') ? (string) $this->input('unit_price') : null;
        }

        return $changes;
    }

    public function startsAt(): ?CarbonImmutable
    {
        return $this->filled('starts_at') ? Date::parse($this->string('starts_at')->toString())->utc() : null;
    }

    public function endsAt(): ?CarbonImmutable
    {
        return $this->filled('ends_at') ? Date::parse($this->string('ends_at')->toString())->utc() : null;
    }

    private function clinicSession(): ClinicSession
    {
        /** @var ClinicSession $session */
        $session = $this->route('clinicSession');

        return $session;
    }

    private function sessionMember(): ClinicSessionMember
    {
        /** @var ClinicSessionMember $sessionMember */
        $sessionMember = $this->route('sessionMember');

        return $sessionMember;
    }
}
