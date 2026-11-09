<?php

namespace App\Http\Requests\Clinic;

use App\Models\Clinic;
use App\Models\DailySession;
use App\Models\DailySessionPatient;
use App\Models\LineItem;
use App\Support\DailyPlanSlots;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class DailySessionPatientRequest extends FormRequest
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
            'patient_id' => [
                Rule::excludeIf(! $this->isMethod('POST')),
                'required',
                'integer',
                Rule::exists('patients', 'id')->where('clinic_id', $clinic->id)->whereNull('deleted_at'),
                Rule::unique('daily_session_patients', 'patient_id')->where('daily_session_id', $this->dailySession()->id),
            ],
            'line_item_id' => ['sometimes', 'integer', Rule::exists('line_items', 'id')->where('clinic_id', $clinic->id)->whereNull('deleted_at')],
            'unit_price' => ['sometimes', 'nullable', 'numeric', 'min:0', 'max:9999999999.99', 'decimal:0,2'],
            'start_time' => ['sometimes', 'nullable', 'date_format:H:i', 'required_with:duration_minutes'],
            'duration_minutes' => ['sometimes', 'nullable', 'integer', 'min:5', 'max:720', 'required_with:start_time'],
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
                if ($validator->errors()->isNotEmpty() || ! $this->has('start_time') || $this->input('start_time') === null) {
                    return;
                }

                $dailySession = $this->dailySession();
                $start = (string) $this->input('start_time');
                $minutes = $this->integer('duration_minutes');

                if (! DailyPlanSlots::fitsWindow($dailySession->start_time, $dailySession->end_time, $start, $minutes)) {
                    $validator->errors()->add('start_time', __('The usual slot must be inside the daily hours.'));

                    return;
                }

                $slots = $dailySession->dailySessionPatients()
                    ->when($this->enrollment(), fn ($query, DailySessionPatient $enrollment) => $query->whereKeyNot($enrollment->id))
                    ->whereNotNull('start_time')
                    ->get()
                    ->map(fn (DailySessionPatient $other): array => ['start' => $other->start_time, 'minutes' => $other->duration_minutes])
                    ->push(['start' => $start, 'minutes' => $minutes])
                    ->values()
                    ->all();

                if (DailyPlanSlots::overlapping($slots) !== []) {
                    $validator->errors()->add('start_time', __('Another patient already has this time as their usual slot.'));
                }
            },
        ];
    }

    /**
     * Get custom messages for validator errors.
     *
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'patient_id.unique' => __('This patient is already enrolled in the daily session.'),
        ];
    }

    /**
     * The validated plan. Choosing a service without a price uses its catalog price.
     *
     * @return array<string, mixed>
     */
    public function plan(): array
    {
        $plan = $this->validated();

        if (isset($plan['line_item_id']) && empty($plan['unit_price'])) {
            $plan['unit_price'] = LineItem::query()->findOrFail((int) $plan['line_item_id'])->price;
        }

        if (array_key_exists('start_time', $plan) && $plan['start_time'] === null) {
            $plan['duration_minutes'] = null;
        }

        return $plan;
    }

    private function dailySession(): DailySession
    {
        /** @var DailySession $dailySession */
        $dailySession = $this->route('dailySession');

        return $dailySession;
    }

    private function enrollment(): ?DailySessionPatient
    {
        /** @var DailySessionPatient|null $enrollment */
        $enrollment = $this->route('dailySessionPatient');

        return $enrollment;
    }
}
