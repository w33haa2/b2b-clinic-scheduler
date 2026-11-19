<?php

namespace App\Http\Requests\Clinic;

use App\Models\Clinic;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

/**
 * Saves when a clinic is open: one window for weekdays, another for the
 * weekend, which weekend days it opens at all, and whether anything may be
 * booked outside those hours.
 */
class ScheduleSettingRequest extends FormRequest
{
    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'weekday_opens_at' => ['sometimes', 'date_format:H:i'],
            'weekday_closes_at' => ['sometimes', 'date_format:H:i'],
            'weekend_opens_at' => ['sometimes', 'date_format:H:i'],
            'weekend_closes_at' => ['sometimes', 'date_format:H:i'],
            'saturday_enabled' => ['sometimes', 'boolean'],
            'sunday_enabled' => ['sometimes', 'boolean'],
            'allow_outside_hours' => ['sometimes', 'boolean'],
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
                /** @var Clinic $clinic */
                $clinic = $this->route('clinic');
                $saved = $clinic->scheduleSettings();

                $pairs = [
                    'weekday_closes_at' => ['weekday_opens_at', __('The weekday hours must end after they start.')],
                    'weekend_closes_at' => ['weekend_opens_at', __('The weekend hours must end after they start.')],
                ];

                foreach ($pairs as $closes => [$opens, $message]) {
                    if ($validator->errors()->hasAny([$opens, $closes])) {
                        continue;
                    }

                    // Either side may be left out, so compare against what is
                    // already saved rather than skipping the check.
                    $from = $this->input($opens, substr($saved->{$opens}, 0, 5));
                    $to = $this->input($closes, substr($saved->{$closes}, 0, 5));

                    if ($to <= $from) {
                        $validator->errors()->add($closes, $message);
                    }
                }
            },
        ];
    }

    /**
     * The settings to save.
     *
     * @return array<string, mixed>
     */
    public function settingAttributes(): array
    {
        return $this->safe()->only([
            'weekday_opens_at',
            'weekday_closes_at',
            'weekend_opens_at',
            'weekend_closes_at',
            'saturday_enabled',
            'sunday_enabled',
            'allow_outside_hours',
        ]);
    }
}
