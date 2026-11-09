<?php

namespace App\Models;

use App\Models\Concerns\RecordsActivity;
use Database\Factories\InvoiceFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int $clinic_id
 * @property int $patient_id
 * @property int $number
 * @property Carbon $issued_at
 * @property string|null $notes
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
#[Fillable(['patient_id', 'number', 'issued_at', 'notes'])]
class Invoice extends Model
{
    /** @use HasFactory<InvoiceFactory> */
    use HasFactory, RecordsActivity;

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'number' => 'integer',
            'issued_at' => 'date',
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
     * @return HasMany<InvoiceItem, $this>
     */
    public function invoiceItems(): HasMany
    {
        return $this->hasMany(InvoiceItem::class);
    }

    /**
     * @return BelongsToMany<SessionLineItem, $this>
     */
    public function sessionLineItems(): BelongsToMany
    {
        return $this->belongsToMany(SessionLineItem::class, 'invoice_items')->withTimestamps();
    }

    /**
     * The human-readable invoice number, e.g. "INV-000042".
     */
    public function reference(): string
    {
        return sprintf('INV-%06d', $this->number);
    }

    public function activityEntity(): string
    {
        return 'invoice';
    }

    public function activityLabel(): string
    {
        $patient = $this->patient;

        return $this->reference().($patient ? " for {$patient->first_name} {$patient->last_name}" : '');
    }
}
