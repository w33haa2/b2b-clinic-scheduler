<?php

namespace App\Models;

use App\Models\Concerns\RecordsActivity;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Database\Factories\ClinicScheduleSettingFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * When a clinic is open. One row per clinic, created on demand with the
 * defaults.
 *
 * Weekdays share one window and the weekend another; Saturday and Sunday are
 * switched on independently. Times are "H:i:s" in the clinic's timezone, the
 * same as a daily session's window.
 *
 * @property int $id
 * @property int $clinic_id
 * @property string $weekday_opens_at
 * @property string $weekday_closes_at
 * @property string $weekend_opens_at
 * @property string $weekend_closes_at
 * @property bool $saturday_enabled
 * @property bool $sunday_enabled
 * @property bool $allow_outside_hours
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
#[Fillable([
    'weekday_opens_at',
    'weekday_closes_at',
    'weekend_opens_at',
    'weekend_closes_at',
    'saturday_enabled',
    'sunday_enabled',
    'allow_outside_hours',
])]
class ClinicScheduleSetting extends Model
{
    /** @use HasFactory<ClinicScheduleSettingFactory> */
    use HasFactory, RecordsActivity;

    public const SATURDAY = 6;

    public const SUNDAY = 7;

    /**
     * The model's default values for attributes.
     *
     * @var array<string, mixed>
     */
    protected $attributes = [
        'weekday_opens_at' => '08:00:00',
        'weekday_closes_at' => '17:00:00',
        'weekend_opens_at' => '09:00:00',
        'weekend_closes_at' => '12:00:00',
        'saturday_enabled' => false,
        'sunday_enabled' => false,
        'allow_outside_hours' => false,
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'saturday_enabled' => 'boolean',
            'sunday_enabled' => 'boolean',
            'allow_outside_hours' => 'boolean',
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
     * Determine whether the clinic opens at all on an ISO weekday (1-7).
     */
    public function opensOnWeekday(int $weekday): bool
    {
        return match ($weekday) {
            self::SATURDAY => $this->saturday_enabled,
            self::SUNDAY => $this->sunday_enabled,
            default => true,
        };
    }

    /**
     * The opening times for an ISO weekday (1-7) as "H:i:s", or null when the
     * clinic is closed that day.
     *
     * @return array{0: string, 1: string}|null
     */
    public function hoursForWeekday(int $weekday): ?array
    {
        if (! $this->opensOnWeekday($weekday)) {
            return null;
        }

        return in_array($weekday, [self::SATURDAY, self::SUNDAY], true)
            ? [$this->weekend_opens_at, $this->weekend_closes_at]
            : [$this->weekday_opens_at, $this->weekday_closes_at];
    }

    /**
     * The opening times for a date, as instants in UTC, or null when closed.
     *
     * @return array{0: CarbonImmutable, 1: CarbonImmutable}|null
     */
    public function windowOn(CarbonInterface $date, ?string $timezone = null): ?array
    {
        $hours = $this->hoursForWeekday((int) $date->isoWeekday());

        if ($hours === null) {
            return null;
        }

        $timezone ??= $this->clinic->timezone ?? 'UTC';
        $day = $date->toDateString();

        return [
            CarbonImmutable::parse("{$day} {$hours[0]}", $timezone)->utc(),
            CarbonImmutable::parse("{$day} {$hours[1]}", $timezone)->utc(),
        ];
    }

    /**
     * Determine whether a range falls inside the opening hours of every date
     * it touches. Always true when the clinic allows booking outside them.
     */
    public function covers(CarbonInterface $startsAt, CarbonInterface $endsAt, ?string $timezone = null): bool
    {
        if ($this->allow_outside_hours) {
            return true;
        }

        $timezone ??= $this->clinic->timezone ?? 'UTC';
        $localStart = CarbonImmutable::parse($startsAt)->setTimezone($timezone);
        $localEnd = CarbonImmutable::parse($endsAt)->setTimezone($timezone);

        // A range that runs past midnight is outside the hours of both dates
        // by definition: no window this model can express covers it.
        if ($localStart->toDateString() !== $localEnd->toDateString()) {
            return false;
        }

        $window = $this->windowOn($localStart, $timezone);

        return $window !== null
            && $localStart->utc()->greaterThanOrEqualTo($window[0])
            && $localEnd->utc()->lessThanOrEqualTo($window[1]);
    }

    /**
     * The widest window across the open days, as "H:i", for sizing the
     * calendar grid and the time pickers. Null when every day is closed.
     *
     * @return array{0: string, 1: string}|null
     */
    public function widestHours(): ?array
    {
        $windows = collect(range(1, 7))
            ->map(fn (int $weekday): ?array => $this->hoursForWeekday($weekday))
            ->filter()
            ->values();

        if ($windows->isEmpty()) {
            return null;
        }

        return [
            substr((string) $windows->min(fn (array $hours): string => $hours[0]), 0, 5),
            substr((string) $windows->max(fn (array $hours): string => $hours[1]), 0, 5),
        ];
    }

    public function activityEntity(): string
    {
        return 'schedule_setting';
    }

    public function activityLabel(): string
    {
        $hours = $this->widestHours();

        return $hours === null ? 'Closed every day' : "{$hours[0]}-{$hours[1]}";
    }
}
