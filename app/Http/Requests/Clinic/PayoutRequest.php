<?php

namespace App\Http\Requests\Clinic;

use App\Models\Clinic;
use App\Models\ClinicUser;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Previews or generates a payout for one member over one period. Both
 * endpoints take the same body, so the preview always matches what
 * generating would save.
 */
class PayoutRequest extends FormRequest
{
    /**
     * The longest period a single payout may cover.
     */
    public const MAX_PERIOD_DAYS = 366;

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
            'clinic_user_id' => [
                'required',
                'integer',
                Rule::exists('clinic_users', 'id')->where('clinic_id', $clinic->id)->whereNull('deleted_at'),
            ],
            'period_start' => ['required', 'date_format:Y-m-d'],
            'period_end' => ['required', 'date_format:Y-m-d', 'after_or_equal:period_start', 'before_or_equal:'.$this->latestEnd()],
            'exclude_session_line_item_ids' => ['sometimes', 'array'],
            'exclude_session_line_item_ids.*' => ['integer', 'distinct'],
            'notes' => ['sometimes', 'nullable', 'string', 'max:1000'],
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
            'period_end.before_or_equal' => __('A payout can cover at most :days days.', ['days' => self::MAX_PERIOD_DAYS]),
        ];
    }

    /**
     * The member being paid.
     */
    public function member(): ClinicUser
    {
        /** @var Clinic $clinic */
        $clinic = $this->route('clinic');

        return $clinic->clinicUsers()->findOrFail($this->integer('clinic_user_id'));
    }

    public function periodStart(): CarbonImmutable
    {
        return CarbonImmutable::parse($this->string('period_start')->toString());
    }

    public function periodEnd(): CarbonImmutable
    {
        return CarbonImmutable::parse($this->string('period_end')->toString());
    }

    /**
     * Items the admin took out of the run.
     *
     * @return list<int>
     */
    public function excludedSessionLineItemIds(): array
    {
        return array_values(array_map(intval(...), (array) $this->input('exclude_session_line_item_ids', [])));
    }

    /**
     * The latest period_end allowed for the period_start that was sent.
     */
    private function latestEnd(): string
    {
        $start = $this->string('period_start')->toString();

        if (! preg_match('/^\d{4}-\d{2}-\d{2}$/', $start)) {
            return CarbonImmutable::now()->addDays(self::MAX_PERIOD_DAYS)->toDateString();
        }

        return CarbonImmutable::parse($start)->addDays(self::MAX_PERIOD_DAYS - 1)->toDateString();
    }
}
