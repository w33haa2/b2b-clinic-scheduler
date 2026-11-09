<?php

namespace App\Models;

use App\Enums\SessionStatus;
use App\Models\Concerns\RecordsActivity;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Database\Factories\ClinicSessionFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * A scheduled session held by one clinic member with one or more patients.
 * Named ClinicSession because Laravel already owns the "sessions" table.
 * A session with a daily_session_id is one day of a DailySession.
 *
 * @property int $id
 * @property int $clinic_id
 * @property int $clinic_user_id
 * @property int|null $daily_session_id
 * @property CarbonImmutable|null $occurs_on
 * @property string|null $name
 * @property Carbon $starts_at
 * @property Carbon $ends_at
 * @property SessionStatus $status_id
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
#[Fillable(['clinic_user_id', 'daily_session_id', 'occurs_on', 'name', 'starts_at', 'ends_at', 'status_id'])]
class ClinicSession extends Model
{
    /** @use HasFactory<ClinicSessionFactory> */
    use HasFactory, RecordsActivity;

    /**
     * The model's default values for attributes.
     *
     * @var array<string, mixed>
     */
    protected $attributes = [
        'status_id' => 1,
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'starts_at' => 'datetime',
            'ends_at' => 'datetime',
            'occurs_on' => 'immutable_date',
            'status_id' => SessionStatus::class,
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
     * The daily session this is one day of, if any.
     *
     * @return BelongsTo<DailySession, $this>
     */
    public function dailySession(): BelongsTo
    {
        return $this->belongsTo(DailySession::class);
    }

    /**
     * @return HasMany<ClinicSessionMember, $this>
     */
    public function sessionMembers(): HasMany
    {
        return $this->hasMany(ClinicSessionMember::class);
    }

    /**
     * @return BelongsToMany<Patient, $this>
     */
    public function patients(): BelongsToMany
    {
        return $this->belongsToMany(Patient::class, 'clinic_session_members')->withTimestamps();
    }

    /**
     * @return HasMany<Payment, $this>
     */
    public function payments(): HasMany
    {
        return $this->hasMany(Payment::class);
    }

    /**
     * Determine whether this session is one day of a daily session.
     */
    public function isDailyOccurrence(): bool
    {
        return $this->daily_session_id !== null;
    }

    /**
     * Sessions that are not cancelled and overlap the given time range. Days
     * of daily sessions are left out: their window does not block the
     * holder, only the patients plotted into it do.
     *
     * @param  Builder<ClinicSession>  $query
     */
    #[Scope]
    protected function overlapping(Builder $query, CarbonInterface $startsAt, CarbonInterface $endsAt): void
    {
        $query->whereNull('daily_session_id')
            ->where('status_id', '!=', SessionStatus::Cancelled)
            ->where('starts_at', '<', $endsAt)
            ->where('ends_at', '>', $startsAt);
    }

    public function activityEntity(): string
    {
        return 'session';
    }

    /**
     * e.g. "Group physio on Tue 4 Nov, 09:00", or "Tue 4 Nov, 09:00" without a
     * name, in the clinic's timezone.
     */
    public function activityLabel(): string
    {
        $timezone = $this->clinic()->value('timezone') ?? 'UTC';
        $when = $this->starts_at->setTimezone($timezone)->format('D j M, H:i');

        return $this->name ? "{$this->name} on {$when}" : $when;
    }

    /**
     * @param  array<string, array{old: mixed, new: mixed}>  $changes
     */
    protected function activityAction(string $event, array $changes): string
    {
        return $event === 'updated' && array_keys($changes) === ['status_id'] ? 'session.status_changed' : "session.{$event}";
    }
}
