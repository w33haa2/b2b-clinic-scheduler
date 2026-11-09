<?php

namespace App\Http\Requests\Clinic;

use App\Enums\AttendanceStatus;
use App\Models\Attendance;
use App\Models\Clinic;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Database\Query\Builder;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Records a member's working day (POST) or changes one (PUT/PATCH). The
 * member and the date are fixed once the row exists; edit the status, times
 * or notes instead.
 */
class AttendanceRequest extends FormRequest
{
    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        $isCreating = $this->isMethod('POST');
        /** @var Clinic $clinic */
        $clinic = $this->route('clinic');

        return [
            'clinic_user_id' => [
                Rule::excludeIf(! $isCreating),
                'required',
                'integer',
                Rule::exists('clinic_users', 'id')->where('clinic_id', $clinic->id)->whereNull('deleted_at'),
                // whereDate, because a date cast is stored with a zero time on SQLite.
                Rule::unique('attendances', 'clinic_user_id')->where(
                    fn (Builder $query) => $query->whereDate('work_date', $this->string('work_date')->toString()),
                ),
            ],
            'work_date' => [Rule::excludeIf(! $isCreating), 'required', 'date_format:Y-m-d'],
            'status' => ['sometimes', 'string', Rule::in(AttendanceStatus::codes())],
            'checked_in_at' => ['sometimes', 'nullable', 'date'],
            'checked_out_at' => ['sometimes', 'nullable', 'date', 'after_or_equal:checked_in_at'],
            'notes' => ['sometimes', 'nullable', 'string', 'max:500'],
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
            'clinic_user_id.unique' => __('This member already has attendance recorded for that day. Edit it instead.'),
        ];
    }

    /**
     * The attributes to save, with the status code turned into its lookup ID.
     *
     * @return array<string, mixed>
     */
    public function attendanceAttributes(): array
    {
        $attributes = $this->safe()->except(['status']);

        if ($this->filled('status')) {
            $attributes['attendance_status_id'] = AttendanceStatus::fromCode($this->string('status')->toString());
        }

        return $attributes;
    }

    /**
     * The attendance row being edited, if any.
     */
    public function attendance(): ?Attendance
    {
        /** @var Attendance|null $attendance */
        $attendance = $this->route('attendance');

        return $attendance;
    }
}
