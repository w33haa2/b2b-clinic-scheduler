<?php

namespace App\Models;

use App\Enums\PatientFormFieldType;
use App\Models\Concerns\RecordsActivity;
use Database\Factories\PatientFormFieldFactory;
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
 * One input on a clinic's patient form.
 *
 * A field with a `patient_column` is **built-in**: it reads and writes that
 * column on `patients`, so the schedule, invoices and patient search keep
 * working. Built-in fields can be relabelled, reordered and made optional,
 * but never deleted, and their column never changes. Every other field is
 * custom, and its answers live in `patient_field_values`.
 *
 * Deleting is a soft delete, so answers already given are never lost and
 * come back if the field is restored.
 *
 * @property int $id
 * @property int $clinic_id
 * @property string $key
 * @property string $label
 * @property string|null $placeholder
 * @property string|null $help_text
 * @property PatientFormFieldType $patient_form_field_type_id
 * @property list<string>|null $options
 * @property array<string, mixed>|null $validation
 * @property bool $is_required
 * @property string $section
 * @property int $position
 * @property string|null $patient_column
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property Carbon|null $deleted_at
 */
#[Fillable([
    'key', 'label', 'placeholder', 'help_text', 'patient_form_field_type_id',
    'options', 'validation', 'is_required', 'section', 'position', 'patient_column',
])]
class PatientFormField extends Model
{
    /** @use HasFactory<PatientFormFieldFactory> */
    use HasFactory, RecordsActivity, SoftDeletes;

    /**
     * Built-in fields whose column the database will not accept as null, so
     * the form cannot make them optional.
     *
     * @var list<string>
     */
    public const ALWAYS_REQUIRED_COLUMNS = ['first_name', 'last_name'];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'patient_form_field_type_id' => PatientFormFieldType::class,
            'options' => 'array',
            'validation' => 'array',
            'is_required' => 'boolean',
            'position' => 'integer',
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
     * @return HasMany<PatientFieldValue, $this>
     */
    public function patientFieldValues(): HasMany
    {
        return $this->hasMany(PatientFieldValue::class);
    }

    /**
     * Determine whether this field is one of the columns on `patients`.
     */
    public function isBuiltIn(): bool
    {
        return $this->patient_column !== null;
    }

    /**
     * The form in display order.
     *
     * @param  Builder<PatientFormField>  $query
     */
    #[Scope]
    protected function inFormOrder(Builder $query): void
    {
        $query->orderBy('position')->orderBy('id');
    }

    /**
     * How many answers this field holds. Custom fields count their stored
     * values; a built-in field counts the patients whose column is filled.
     */
    public function answerCount(): int
    {
        if (! $this->isBuiltIn()) {
            return $this->patientFieldValues()->whereNotNull('value')->where('value', '!=', '')->count();
        }

        return Patient::query()
            ->where('clinic_id', $this->clinic_id)
            ->whereNotNull($this->patient_column)
            ->where($this->patient_column, '!=', '')
            ->count();
    }

    public function activityEntity(): string
    {
        return 'patient_form_field';
    }

    public function activityLabel(): string
    {
        return $this->label;
    }

    /**
     * @return array<string, mixed>
     */
    public function activityContext(): array
    {
        return ['key' => $this->key, 'built_in' => $this->isBuiltIn()];
    }

    /**
     * @param  array<string, array{old: mixed, new: mixed}>  $changes
     */
    protected function activityAction(string $event, array $changes): string
    {
        if ($event === 'updated' && array_keys($changes) === ['position']) {
            return 'patient_form_field.moved';
        }

        return match ($event) {
            'archived', 'deleted' => 'patient_form_field.removed',
            default => "patient_form_field.{$event}",
        };
    }
}
