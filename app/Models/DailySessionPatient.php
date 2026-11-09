<?php

namespace App\Models;

use App\Models\Concerns\RecordsActivity;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * A patient enrolled in a daily session, with their plan: the service they
 * are billed every day and their usual slot (clinic timezone), which is
 * plotted automatically on each day where the staff member is free.
 *
 * @property int $id
 * @property int $daily_session_id
 * @property int $patient_id
 * @property int $line_item_id
 * @property string $unit_price
 * @property string|null $start_time
 * @property int|null $duration_minutes
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
#[Fillable(['patient_id', 'line_item_id', 'unit_price', 'start_time', 'duration_minutes'])]
class DailySessionPatient extends Model
{
    use RecordsActivity;

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'unit_price' => 'decimal:2',
            'duration_minutes' => 'integer',
        ];
    }

    /**
     * @return BelongsTo<DailySession, $this>
     */
    public function dailySession(): BelongsTo
    {
        return $this->belongsTo(DailySession::class);
    }

    /**
     * @return BelongsTo<Patient, $this>
     */
    public function patient(): BelongsTo
    {
        return $this->belongsTo(Patient::class)->withTrashed();
    }

    /**
     * The service billed to this patient every day.
     *
     * @return BelongsTo<LineItem, $this>
     */
    public function lineItem(): BelongsTo
    {
        return $this->belongsTo(LineItem::class)->withTrashed();
    }

    /**
     * The usual slot on a given date (clinic timezone), in UTC; null without one.
     *
     * @return array{0: CarbonImmutable, 1: CarbonImmutable}|null
     */
    public function usualSlotOn(CarbonInterface $date, string $timezone): ?array
    {
        if ($this->start_time === null || ! $this->duration_minutes) {
            return null;
        }

        $startsAt = CarbonImmutable::parse("{$date->toDateString()} {$this->start_time}", $timezone)->utc();

        return [$startsAt, $startsAt->addMinutes($this->duration_minutes)];
    }

    public function activityEntity(): string
    {
        return 'daily_session_patient';
    }

    public function activityLabel(): string
    {
        $patient = $this->patient;

        return $patient ? "{$patient->first_name} {$patient->last_name}" : "Patient #{$this->patient_id}";
    }

    public function activityClinicId(): ?int
    {
        return $this->dailySession?->clinic_id;
    }

    /**
     * @return array<string, mixed>
     */
    public function activityContext(): array
    {
        return ['daily_session' => $this->dailySession?->name];
    }

    /**
     * @param  array<string, array{old: mixed, new: mixed}>  $changes
     */
    protected function activityAction(string $event, array $changes): string
    {
        return match ($event) {
            'created' => 'daily_session_patient.enrolled',
            'deleted' => 'daily_session_patient.removed',
            'updated' => 'daily_session_patient.plan_changed',
            default => "daily_session_patient.{$event}",
        };
    }
}
