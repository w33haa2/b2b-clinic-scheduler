<?php

namespace App\Http\Requests\Clinic;

use App\Models\Clinic;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Date;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

/**
 * Moves one patient off a day of a daily session and into a makeup session
 * on another date.
 */
class RescheduleSessionMemberRequest extends FormRequest
{
    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'starts_at' => ['required', 'date'],
            'ends_at' => ['required', 'date'],
            // Left out, the makeup stays with the member who holds the day.
            'clinic_user_id' => ['sometimes', 'nullable', 'integer', Rule::exists('clinic_users', 'id')
                ->where('clinic_id', $this->clinic()->id)
                ->whereNull('deleted_at')],
            'reason' => ['sometimes', 'nullable', 'string', 'max:255'],
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
                if ($validator->errors()->hasAny(['starts_at', 'ends_at'])) {
                    return;
                }

                $startsAt = $this->startsAt();
                $endsAt = $this->endsAt();

                if ($endsAt->lessThanOrEqualTo($startsAt)) {
                    $validator->errors()->add('ends_at', __('The makeup must end after it starts.'));

                    return;
                }

                if (! $this->clinic()->scheduleSettings()->covers($startsAt, $endsAt)) {
                    $validator->errors()->add('starts_at', __('The clinic is closed then. Change the time, or allow booking outside opening hours in the schedule settings.'));
                }
            },
        ];
    }

    /**
     * The makeup to create, with times in UTC.
     *
     * @return array{starts_at: CarbonImmutable, ends_at: CarbonImmutable, clinic_user_id: int|null, reason: string|null}
     */
    public function makeup(): array
    {
        return [
            'starts_at' => $this->startsAt(),
            'ends_at' => $this->endsAt(),
            'clinic_user_id' => $this->integer('clinic_user_id') ?: null,
            'reason' => $this->string('reason')->trim()->value() ?: null,
        ];
    }

    private function startsAt(): CarbonImmutable
    {
        return Date::parse($this->string('starts_at')->toString())->utc();
    }

    private function endsAt(): CarbonImmutable
    {
        return Date::parse($this->string('ends_at')->toString())->utc();
    }

    private function clinic(): Clinic
    {
        /** @var Clinic $clinic */
        $clinic = $this->route('clinic');

        return $clinic;
    }
}
