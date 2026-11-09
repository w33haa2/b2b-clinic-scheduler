<?php

namespace App\Models;

use App\Enums\ProgressRating;
use App\Models\Concerns\RecordsActivity;
use Carbon\CarbonImmutable;
use Database\Factories\PatientProgressNoteFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Carbon;

/**
 * How a patient is doing on one day, written by the member who saw them.
 * Notes build up into a history the clinic can read at a glance: each one
 * rates the change since the last, and can carry measurements (pain, range
 * of motion, weight) that are charted over time.
 *
 * @property int $id
 * @property int $clinic_id
 * @property int $patient_id
 * @property int|null $clinic_user_id
 * @property int|null $clinic_session_id
 * @property CarbonImmutable $recorded_on
 * @property ProgressRating $progress_rating_id
 * @property string $summary
 * @property string|null $body
 * @property string|null $plan
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property Carbon|null $deleted_at
 */
#[Fillable(['patient_id', 'clinic_user_id', 'clinic_session_id', 'recorded_on', 'progress_rating_id', 'summary', 'body', 'plan'])]
class PatientProgressNote extends Model
{
    /** @use HasFactory<PatientProgressNoteFactory> */
    use HasFactory, RecordsActivity, SoftDeletes;

    /**
     * The model's default values for attributes.
     *
     * @var array<string, mixed>
     */
    protected $attributes = [
        'progress_rating_id' => 1,
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'recorded_on' => 'immutable_date',
            'progress_rating_id' => ProgressRating::class,
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
     * @return BelongsTo<Patient, $this>
     */
    public function patient(): BelongsTo
    {
        return $this->belongsTo(Patient::class)->withTrashed();
    }

    /**
     * The member who wrote it.
     *
     * @return BelongsTo<ClinicUser, $this>
     */
    public function clinicUser(): BelongsTo
    {
        return $this->belongsTo(ClinicUser::class)->withTrashed();
    }

    /**
     * The session it came out of, if any.
     *
     * @return BelongsTo<ClinicSession, $this>
     */
    public function clinicSession(): BelongsTo
    {
        return $this->belongsTo(ClinicSession::class);
    }

    /**
     * @return HasMany<PatientProgressMetric, $this>
     */
    public function metrics(): HasMany
    {
        return $this->hasMany(PatientProgressMetric::class);
    }

    /**
     * Notes oldest first, the order a history reads in.
     *
     * @param  Builder<PatientProgressNote>  $query
     */
    #[Scope]
    protected function chronological(Builder $query): void
    {
        $query->orderBy('recorded_on')->orderBy('id');
    }

    public function activityEntity(): string
    {
        return 'progress_note';
    }

    public function activityLabel(): string
    {
        return $this->summary;
    }

    /**
     * @return array<string, mixed>
     */
    public function activityContext(): array
    {
        return ['patient' => $this->patient?->activityLabel()];
    }

    /**
     * The clinical detail is not repeated into the audit trail; the log says
     * a note changed, not what the patient told their therapist.
     *
     * @return list<string>
     */
    protected function activityRedactedAttributes(): array
    {
        return ['body', 'plan'];
    }
}
