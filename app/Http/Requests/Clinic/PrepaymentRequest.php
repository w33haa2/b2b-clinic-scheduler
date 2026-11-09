<?php

namespace App\Http\Requests\Clinic;

use App\Models\Clinic;
use App\Models\DailySession;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class PrepaymentRequest extends FormRequest
{
    /**
     * The furthest ahead a day can be prepaid.
     */
    public const MAX_DAYS_AHEAD = 90;

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        $clinic = $this->clinic();

        return [
            'daily_session_id' => ['required', 'integer', Rule::exists('daily_sessions', 'id')->where('clinic_id', $clinic->id)],
            'patient_id' => ['required', 'integer', Rule::exists('patients', 'id')->where('clinic_id', $clinic->id)->whereNull('deleted_at')],
            'dates' => ['required', 'array', 'min:1', 'max:'.self::MAX_DAYS_AHEAD],
            'dates.*' => ['required', 'date_format:Y-m-d', 'distinct'],
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

                $dailySession = $this->dailySession();

                if (! $dailySession->dailySessionPatients()->where('patient_id', $this->integer('patient_id'))->exists()) {
                    $validator->errors()->add('patient_id', __('This patient is not enrolled in the daily session.'));

                    return;
                }

                $today = $dailySession->today();
                $limit = $today->addDays(self::MAX_DAYS_AHEAD);

                $invalid = collect($this->dates())
                    ->map(fn (string $date): CarbonImmutable => CarbonImmutable::parse($date))
                    ->reject(fn (CarbonImmutable $date): bool => $date->betweenIncluded($today, $limit) && $dailySession->runsOn($date));

                if ($invalid->isNotEmpty()) {
                    $validator->errors()->add('dates', __('The daily session does not run on :dates, or it is in the past or too far ahead.', [
                        'dates' => $invalid->map(fn (CarbonImmutable $date): string => $date->format('D j M Y'))->implode(', '),
                    ]));
                }
            },
        ];
    }

    public function dailySession(): DailySession
    {
        return $this->clinic()->dailySessions()->findOrFail($this->integer('daily_session_id'));
    }

    /**
     * @return list<string>
     */
    public function dates(): array
    {
        return array_values(array_map(strval(...), (array) $this->input('dates', [])));
    }

    private function clinic(): Clinic
    {
        /** @var Clinic $clinic */
        $clinic = $this->route('clinic');

        return $clinic;
    }
}
