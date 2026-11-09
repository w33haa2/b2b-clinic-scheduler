<?php

namespace App\Models;

use Database\Factories\PatientProgressMetricFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * One measurement on a progress note, e.g. "Pain" 6 "/10". The name is free
 * text so each clinic tracks what matters to it; the Progress notes page
 * charts every measurement a patient has by name.
 *
 * @property int $id
 * @property int $patient_progress_note_id
 * @property string $name
 * @property string $value
 * @property string|null $unit
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
#[Fillable(['name', 'value', 'unit'])]
class PatientProgressMetric extends Model
{
    /** @use HasFactory<PatientProgressMetricFactory> */
    use HasFactory;

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'value' => 'decimal:2',
        ];
    }

    /**
     * @return BelongsTo<PatientProgressNote, $this>
     */
    public function patientProgressNote(): BelongsTo
    {
        return $this->belongsTo(PatientProgressNote::class);
    }
}
