<?php

namespace App\Models;

use App\Enums\AttendanceStatus;
use App\Models\Concerns\RecordsActivity;
use Carbon\CarbonImmutable;
use Database\Factories\AttendanceFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * One clinic member's attendance on one working day. The day is a date in the
 * clinic's timezone, so it lines up with the days of a daily session.
 *
 * @property int $id
 * @property int $clinic_id
 * @property int $clinic_user_id
 * @property CarbonImmutable $work_date
 * @property AttendanceStatus $attendance_status_id
 * @property Carbon|null $checked_in_at
 * @property Carbon|null $checked_out_at
 * @property string|null $notes
 * @property int|null $recorded_by_clinic_user_id
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
#[Fillable(['clinic_user_id', 'work_date', 'attendance_status_id', 'checked_in_at', 'checked_out_at', 'notes', 'recorded_by_clinic_user_id'])]
class Attendance extends Model
{
    /** @use HasFactory<AttendanceFactory> */
    use HasFactory, RecordsActivity;

    /**
     * The model's default values for attributes.
     *
     * @var array<string, mixed>
     */
    protected $attributes = [
        'attendance_status_id' => 1,
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'work_date' => 'immutable_date',
            'attendance_status_id' => AttendanceStatus::class,
            'checked_in_at' => 'datetime',
            'checked_out_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<Clinic, $this>
     */
    public function clinic(): BelongsTo
    {
        return $this->belongsTo(Clinic::class);
    }

    /**
     * The member the day is about.
     *
     * @return BelongsTo<ClinicUser, $this>
     */
    public function clinicUser(): BelongsTo
    {
        return $this->belongsTo(ClinicUser::class)->withTrashed();
    }

    /**
     * The member who wrote the row down, which is not always its subject.
     *
     * @return BelongsTo<ClinicUser, $this>
     */
    public function recordedBy(): BelongsTo
    {
        return $this->belongsTo(ClinicUser::class, 'recorded_by_clinic_user_id')->withTrashed();
    }

    /**
     * Days that count as worked, for payouts.
     *
     * @param  Builder<Attendance>  $query
     */
    #[Scope]
    protected function worked(Builder $query): void
    {
        $query->whereIn('attendance_status_id', [AttendanceStatus::Present, AttendanceStatus::Late]);
    }

    /**
     * Minutes between check-in and check-out, when both are recorded.
     */
    public function workedMinutes(): ?int
    {
        if ($this->checked_in_at === null || $this->checked_out_at === null) {
            return null;
        }

        return (int) $this->checked_in_at->diffInMinutes($this->checked_out_at);
    }

    public function activityEntity(): string
    {
        return 'attendance';
    }

    /**
     * e.g. "Ana Cruz on 2026-11-07".
     */
    public function activityLabel(): string
    {
        $name = $this->clinicUser?->activityLabel() ?? "Member #{$this->clinic_user_id}";

        return "{$name} on {$this->work_date->toDateString()}";
    }
}
