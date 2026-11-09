<?php

namespace App\Models;

use App\Enums\Sex;
use App\Models\Concerns\RecordsActivity;
use Database\Factories\PatientFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Carbon;

/**
 * A patient of one clinic.
 *
 * The columns here are the **built-in** fields of the clinic's patient form
 * (see PatientFormField): the form can relabel and reorder them, but they
 * stay real columns because the schedule, invoices and patient search read
 * them. Anything the clinic adds to its form is stored in
 * `patient_field_values`. `clinic_id` is the tenant key and is never
 * writable: it is not in #[Fillable] and is not a form field.
 *
 * @property int $id
 * @property int $clinic_id
 * @property string $first_name
 * @property string|null $middle_name
 * @property string $last_name
 * @property Carbon|null $birth_date
 * @property Sex|null $sex
 * @property string|null $email
 * @property string|null $phone
 * @property string|null $address
 * @property string|null $notes
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property Carbon|null $deleted_at
 */
#[Fillable(['first_name', 'middle_name', 'last_name', 'birth_date', 'sex', 'email', 'phone', 'address', 'notes'])]
class Patient extends Model
{
    /** @use HasFactory<PatientFactory> */
    use HasFactory, RecordsActivity, SoftDeletes;

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'birth_date' => 'date',
            'sex' => Sex::class,
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
     * The patient's attendance in sessions.
     *
     * @return HasMany<ClinicSessionMember, $this>
     */
    public function sessionMembers(): HasMany
    {
        return $this->hasMany(ClinicSessionMember::class);
    }

    /**
     * @return HasMany<Payment, $this>
     */
    public function payments(): HasMany
    {
        return $this->hasMany(Payment::class);
    }

    /**
     * @return HasMany<Invoice, $this>
     */
    public function invoices(): HasMany
    {
        return $this->hasMany(Invoice::class);
    }

    /**
     * Answers to the clinic's custom form fields.
     *
     * @return HasMany<PatientFieldValue, $this>
     */
    public function patientFieldValues(): HasMany
    {
        return $this->hasMany(PatientFieldValue::class);
    }

    /**
     * How the patient has been doing, oldest first.
     *
     * @return HasMany<PatientProgressNote, $this>
     */
    public function progressNotes(): HasMany
    {
        return $this->hasMany(PatientProgressNote::class);
    }

    /**
     * Intake links sent for this patient.
     *
     * @return HasMany<PatientFormInvitation, $this>
     */
    public function patientFormInvitations(): HasMany
    {
        return $this->hasMany(PatientFormInvitation::class);
    }

    public function activityEntity(): string
    {
        return 'patient';
    }

    public function activityLabel(): string
    {
        return "{$this->first_name} {$this->last_name}";
    }

    /**
     * Personal details: the log says they changed, not what they changed to.
     *
     * @return list<string>
     */
    protected function activityRedactedAttributes(): array
    {
        return ['birth_date', 'sex', 'email', 'phone', 'address', 'notes'];
    }
}
