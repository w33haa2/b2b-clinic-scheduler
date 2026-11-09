<?php

namespace App\Models;

use App\Models\Concerns\RecordsActivity;
use Database\Factories\ClinicFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property string $name
 * @property string|null $address
 * @property string $timezone
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property Carbon|null $deleted_at
 */
#[Fillable(['name', 'address', 'timezone'])]
class Clinic extends Model
{
    /** @use HasFactory<ClinicFactory> */
    use HasFactory, RecordsActivity, SoftDeletes;

    /**
     * The clinic's memberships.
     *
     * @return HasMany<ClinicUser, $this>
     */
    public function clinicUsers(): HasMany
    {
        return $this->hasMany(ClinicUser::class);
    }

    /**
     * The users who are members of the clinic.
     *
     * @return BelongsToMany<User, $this>
     */
    public function users(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'clinic_users')
            ->withTimestamps()
            ->wherePivotNull('deleted_at');
    }

    /**
     * @return HasMany<ClinicRole, $this>
     */
    public function clinicRoles(): HasMany
    {
        return $this->hasMany(ClinicRole::class);
    }

    /**
     * @return HasMany<Patient, $this>
     */
    public function patients(): HasMany
    {
        return $this->hasMany(Patient::class);
    }

    /**
     * @return HasMany<ClinicSession, $this>
     */
    public function clinicSessions(): HasMany
    {
        return $this->hasMany(ClinicSession::class);
    }

    /**
     * Emails invited to join the clinic.
     *
     * @return HasMany<ClinicInvitation, $this>
     */
    public function clinicInvitations(): HasMany
    {
        return $this->hasMany(ClinicInvitation::class);
    }

    /**
     * @return HasMany<DailySession, $this>
     */
    public function dailySessions(): HasMany
    {
        return $this->hasMany(DailySession::class);
    }

    /**
     * @return HasMany<Payment, $this>
     */
    public function payments(): HasMany
    {
        return $this->hasMany(Payment::class);
    }

    /**
     * Schedule filters saved by the clinic's members (each sees only their own).
     *
     * @return HasMany<CalendarFilter, $this>
     */
    public function calendarFilters(): HasMany
    {
        return $this->hasMany(CalendarFilter::class);
    }

    /**
     * The clinic's billable item catalog.
     *
     * @return HasMany<LineItem, $this>
     */
    public function lineItems(): HasMany
    {
        return $this->hasMany(LineItem::class);
    }

    /**
     * @return HasMany<Transaction, $this>
     */
    public function transactions(): HasMany
    {
        return $this->hasMany(Transaction::class);
    }

    /**
     * @return HasMany<ActivityLog, $this>
     */
    public function activityLogs(): HasMany
    {
        return $this->hasMany(ActivityLog::class);
    }

    /**
     * @return HasMany<Invoice, $this>
     */
    public function invoices(): HasMany
    {
        return $this->hasMany(Invoice::class);
    }

    /**
     * How the clinic splits what it bills with its staff. Created on demand
     * with the defaults; use payoutSettings() to read it.
     *
     * @return HasOne<ClinicPayoutSetting, $this>
     */
    public function clinicPayoutSetting(): HasOne
    {
        return $this->hasOne(ClinicPayoutSetting::class);
    }

    /**
     * @return HasMany<Attendance, $this>
     */
    public function attendances(): HasMany
    {
        return $this->hasMany(Attendance::class);
    }

    /**
     * Clinic-wide and personal discounts.
     *
     * @return HasMany<Discount, $this>
     */
    public function discounts(): HasMany
    {
        return $this->hasMany(Discount::class);
    }

    /**
     * @return HasMany<Payout, $this>
     */
    public function payouts(): HasMany
    {
        return $this->hasMany(Payout::class);
    }

    /**
     * The inputs on the clinic's patient form.
     *
     * @return HasMany<PatientFormField, $this>
     */
    public function patientFormFields(): HasMany
    {
        return $this->hasMany(PatientFormField::class);
    }

    /**
     * @return HasMany<PatientProgressNote, $this>
     */
    public function patientProgressNotes(): HasMany
    {
        return $this->hasMany(PatientProgressNote::class);
    }

    /**
     * Links sent to patients so they can fill the form in themselves.
     *
     * @return HasMany<PatientFormInvitation, $this>
     */
    public function patientFormInvitations(): HasMany
    {
        return $this->hasMany(PatientFormInvitation::class);
    }

    /**
     * The clinic's payout configuration, created with the defaults the first
     * time it is needed.
     */
    public function payoutSettings(): ClinicPayoutSetting
    {
        return $this->clinicPayoutSetting()->firstOrCreate([]);
    }

    public function activityEntity(): string
    {
        return 'clinic';
    }

    public function activityLabel(): string
    {
        return $this->name;
    }

    public function activityClinicId(): ?int
    {
        return $this->id;
    }
}
