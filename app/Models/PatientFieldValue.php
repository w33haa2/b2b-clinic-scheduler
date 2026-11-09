<?php

namespace App\Models;

use Database\Factories\PatientFieldValueFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * One patient's answer to one custom field. Built-in fields are not stored
 * here; they live in their own column on `patients`.
 *
 * @property int $id
 * @property int $patient_id
 * @property int $patient_form_field_id
 * @property string|null $value
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
#[Fillable(['patient_form_field_id', 'value'])]
class PatientFieldValue extends Model
{
    /** @use HasFactory<PatientFieldValueFactory> */
    use HasFactory;

    /**
     * @return BelongsTo<Patient, $this>
     */
    public function patient(): BelongsTo
    {
        return $this->belongsTo(Patient::class);
    }

    /**
     * @return BelongsTo<PatientFormField, $this>
     */
    public function patientFormField(): BelongsTo
    {
        return $this->belongsTo(PatientFormField::class)->withTrashed();
    }

    /**
     * The answer in the shape its field uses: a list for a multi-select, a
     * bool for a checkbox, otherwise the stored string.
     */
    public function typedValue(): mixed
    {
        $field = $this->patientFormField;

        if ($field === null || $this->value === null) {
            return $this->value;
        }

        return match (true) {
            $field->patient_form_field_type_id->isMultiValue() => json_decode($this->value, true) ?: [],
            $field->patient_form_field_type_id->baseRules() === ['boolean'] => (bool) $this->value,
            default => $this->value,
        };
    }

    /**
     * Turn an answer into the string stored in `value`.
     */
    public static function encode(mixed $value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }

        if (is_array($value)) {
            return json_encode(array_values($value)) ?: null;
        }

        if (is_bool($value)) {
            return $value ? '1' : '0';
        }

        return (string) $value;
    }
}
