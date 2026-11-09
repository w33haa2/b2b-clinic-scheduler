<?php

namespace App\Http\Requests\Clinic;

use App\Models\Clinic;
use App\Models\DailySession;
use App\Models\LineItem;
use App\Support\DailyPlanSlots;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class DailySessionRequest extends FormRequest
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
            'name' => [$required, 'string', 'max:255'],
            'clinic_user_id' => [
                $required,
                'integer',
                Rule::exists('clinic_users', 'id')->where('clinic_id', $clinic->id)->whereNull('deleted_at'),
                Rule::unique('daily_sessions', 'clinic_user_id')->ignore($this->dailySession()),
            ],
            'line_item_id' => [$required, 'integer', Rule::exists('line_items', 'id')
                ->where('clinic_id', $clinic->id)
                ->whereNull('deleted_at')],
            'unit_price' => ['sometimes', 'numeric', 'min:0', 'max:9999999999.99', 'decimal:0,2'],
            'start_time' => [$required, 'date_format:H:i'],
            'end_time' => [$required, 'date_format:H:i'],
            'weekdays' => [$required, 'array', 'min:1', 'max:7'],
            'weekdays.*' => ['integer', 'distinct', 'between:1,7'],
            'starts_on' => [$required, 'date_format:Y-m-d'],
            'ends_on' => ['sometimes', 'nullable', 'date_format:Y-m-d'],
            'patient_ids' => [Rule::excludeIf(! $this->isMethod('POST')), 'array'],
            'patient_ids.*' => ['integer', 'distinct', Rule::exists('patients', 'id')
                ->where('clinic_id', $clinic->id)
                ->whereNull('deleted_at')],
            // Patients with their plans: service, price and usual slot.
            'patients' => ['sometimes', 'array', 'max:200'],
            'patients.*' => ['array:patient_id,line_item_id,unit_price,start_time,duration_minutes'],
            'patients.*.patient_id' => ['required', 'integer', 'distinct', Rule::exists('patients', 'id')
                ->where('clinic_id', $clinic->id)
                ->whereNull('deleted_at')],
            'patients.*.line_item_id' => ['sometimes', 'nullable', 'integer', Rule::exists('line_items', 'id')
                ->where('clinic_id', $clinic->id)
                ->whereNull('deleted_at')],
            'patients.*.unit_price' => ['sometimes', 'nullable', 'numeric', 'min:0', 'max:9999999999.99', 'decimal:0,2'],
            'patients.*.start_time' => ['sometimes', 'nullable', 'date_format:H:i'],
            'patients.*.duration_minutes' => ['sometimes', 'nullable', 'integer', 'min:5', 'max:720', 'required_with:patients.*.start_time'],
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
            'clinic_user_id.unique' => __('This staff member already has a daily session. Edit it instead of creating another.'),
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
                if ($validator->errors()->hasAny(['start_time', 'end_time', 'starts_on', 'ends_on'])) {
                    return;
                }

                $dailySession = $this->dailySession();
                $startTime = $this->input('start_time', $dailySession ? substr($dailySession->start_time, 0, 5) : null);
                $endTime = $this->input('end_time', $dailySession ? substr($dailySession->end_time, 0, 5) : null);

                if ($startTime !== null && $endTime !== null && $endTime <= $startTime) {
                    $validator->errors()->add('end_time', __('The daily window must end after it starts, on the same day.'));
                }

                $startsOn = $this->input('starts_on', $dailySession?->starts_on->toDateString());
                $endsOn = $this->has('ends_on') ? $this->input('ends_on') : $dailySession?->ends_on?->toDateString();

                if ($startsOn !== null && $endsOn !== null && $endsOn < $startsOn) {
                    $validator->errors()->add('ends_on', __('The end date cannot be before the start date.'));
                }

                if ($startTime === null || $endTime === null || $validator->errors()->has('patients.*')) {
                    return;
                }

                $slots = collect((array) $this->input('patients', []))
                    ->map(fn (mixed $plan): array => [
                        'start' => is_array($plan) ? ($plan['start_time'] ?? null) : null,
                        'minutes' => is_array($plan) ? (int) ($plan['duration_minutes'] ?? 0) : 0,
                    ]);

                foreach ($slots as $index => $slot) {
                    if ($slot['start'] !== null && $slot['minutes'] > 0 && ! DailyPlanSlots::fitsWindow($startTime, $endTime, $slot['start'], $slot['minutes'])) {
                        $validator->errors()->add("patients.{$index}.start_time", __('The usual slot must be inside the daily hours.'));
                    }
                }

                foreach (DailyPlanSlots::overlapping($slots->all()) as $index) {
                    $validator->errors()->add("patients.{$index}.start_time", __('This usual slot overlaps another patient\'s.'));
                }
            },
        ];
    }

    /**
     * The validated attributes. The price defaults to the catalog price of the
     * service when a service is chosen without one.
     *
     * @return array<string, mixed>
     */
    public function dailySessionAttributes(): array
    {
        $attributes = $this->safe()->except(['patient_ids', 'patients']);

        if (isset($attributes['weekdays'])) {
            $weekdays = array_map(intval(...), $attributes['weekdays']);
            sort($weekdays);
            $attributes['weekdays'] = array_values(array_unique($weekdays));
        }

        if (isset($attributes['line_item_id']) && ! isset($attributes['unit_price'])) {
            $attributes['unit_price'] = LineItem::query()->findOrFail((int) $attributes['line_item_id'])->price;
        }

        return $attributes;
    }

    /**
     * On update, the full list of plans to sync, or null to leave the patients alone.
     *
     * @return list<array<string, mixed>>|null
     */
    public function patientPlans(): ?array
    {
        return $this->has('patients') ? array_values($this->validated('patients', [])) : null;
    }

    /**
     * The patients to enrol: plans from `patients`, or plain IDs from `patient_ids`.
     *
     * @return list<int|array<string, mixed>>
     */
    public function patients(): array
    {
        return array_values([
            ...array_map(intval(...), $this->validated('patient_ids', [])),
            ...$this->validated('patients', []),
        ]);
    }

    private function clinic(): Clinic
    {
        /** @var Clinic $clinic */
        $clinic = $this->route('clinic');

        return $clinic;
    }

    private function dailySession(): ?DailySession
    {
        /** @var DailySession|null $dailySession */
        $dailySession = $this->route('dailySession');

        return $dailySession;
    }
}
