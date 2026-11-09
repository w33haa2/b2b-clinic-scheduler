<?php

namespace App\Models;

use App\Models\Concerns\RecordsActivity;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Database\Factories\DailySessionFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * A session that repeats every day (or on chosen weekdays) inside a daily
 * time window. Patients are enrolled once; each day becomes a ClinicSession
 * (an "occurrence") where every enrolled patient is billed the session's
 * service and gets a payment, and can be plotted into a free time slot.
 *
 * @property int $id
 * @property int $clinic_id
 * @property int $clinic_user_id
 * @property string $name
 * @property int $line_item_id
 * @property string $unit_price
 * @property string $start_time
 * @property string $end_time
 * @property list<int> $weekdays
 * @property CarbonImmutable $starts_on
 * @property CarbonImmutable|null $ends_on
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
#[Fillable(['clinic_user_id', 'name', 'line_item_id', 'unit_price', 'start_time', 'end_time', 'weekdays', 'starts_on', 'ends_on'])]
class DailySession extends Model
{
    /** @use HasFactory<DailySessionFactory> */
    use HasFactory, RecordsActivity;

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'unit_price' => 'decimal:2',
            'weekdays' => 'array',
            'starts_on' => 'immutable_date',
            'ends_on' => 'immutable_date',
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
     * The member holding the session.
     *
     * @return BelongsTo<ClinicUser, $this>
     */
    public function clinicUser(): BelongsTo
    {
        return $this->belongsTo(ClinicUser::class)->withTrashed();
    }

    /**
     * The service billed to each patient every day.
     *
     * @return BelongsTo<LineItem, $this>
     */
    public function lineItem(): BelongsTo
    {
        return $this->belongsTo(LineItem::class)->withTrashed();
    }

    /**
     * Enrolments: the patients who attend every day.
     *
     * @return HasMany<DailySessionPatient, $this>
     */
    public function dailySessionPatients(): HasMany
    {
        return $this->hasMany(DailySessionPatient::class);
    }

    /**
     * @return BelongsToMany<Patient, $this>
     */
    public function patients(): BelongsToMany
    {
        return $this->belongsToMany(Patient::class, 'daily_session_patients')->withTimestamps();
    }

    /**
     * The days that have been scheduled, one clinic session each.
     *
     * @return HasMany<ClinicSession, $this>
     */
    public function clinicSessions(): HasMany
    {
        return $this->hasMany(ClinicSession::class);
    }

    /**
     * The clinic's timezone, which the window and dates are in.
     */
    public function timezone(): string
    {
        return $this->clinic->timezone ?? 'UTC';
    }

    /**
     * Today's date in the clinic's timezone.
     */
    public function today(): CarbonImmutable
    {
        return CarbonImmutable::now($this->timezone())->startOfDay();
    }

    /**
     * Determine whether the session runs on the given date (in the clinic's timezone).
     */
    public function runsOn(CarbonInterface $date): bool
    {
        $day = $date->toDateString();

        return $day >= $this->starts_on->toDateString()
            && ($this->ends_on === null || $day <= $this->ends_on->toDateString())
            && in_array($date->isoWeekday(), array_map(intval(...), $this->weekdays), true);
    }

    /**
     * The dates the session runs on between two dates, inclusive.
     *
     * @return Collection<int, CarbonImmutable>
     */
    public function datesBetween(CarbonInterface $from, CarbonInterface $to): Collection
    {
        $dates = collect();

        for ($date = CarbonImmutable::parse($from->toDateString()); $date->toDateString() <= $to->toDateString(); $date = $date->addDay()) {
            if ($this->runsOn($date)) {
                $dates->push($date);
            }
        }

        return $dates;
    }

    /**
     * The window on a given date, converted to UTC.
     *
     * @return array{0: CarbonImmutable, 1: CarbonImmutable}
     */
    public function windowOn(CarbonInterface $date): array
    {
        $timezone = $this->timezone();
        $day = $date->toDateString();

        return [
            CarbonImmutable::parse("{$day} {$this->start_time}", $timezone)->utc(),
            CarbonImmutable::parse("{$day} {$this->end_time}", $timezone)->utc(),
        ];
    }

    /**
     * A patient's plan with defaults filled in: this session's service (at
     * its price), or the chosen service at its catalog price; no usual slot
     * unless both a start time and a length are given.
     *
     * @param  array<string, mixed>  $plan
     * @return array<string, mixed>
     */
    public function planAttributes(array $plan): array
    {
        if (empty($plan['line_item_id'])) {
            $plan['line_item_id'] = $this->line_item_id;
            $plan['unit_price'] = empty($plan['unit_price']) ? $this->unit_price : $plan['unit_price'];
        }

        if (empty($plan['unit_price'])) {
            $plan['unit_price'] = LineItem::query()->findOrFail((int) $plan['line_item_id'])->price;
        }

        if (empty($plan['start_time']) || empty($plan['duration_minutes'])) {
            $plan['start_time'] = null;
            $plan['duration_minutes'] = null;
        }

        return array_intersect_key($plan, array_flip((new DailySessionPatient)->getFillable()));
    }

    public function activityEntity(): string
    {
        return 'daily_session';
    }

    public function activityLabel(): string
    {
        return $this->name;
    }
}
