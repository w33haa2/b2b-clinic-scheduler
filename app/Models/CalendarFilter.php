<?php

namespace App\Models;

use App\Models\Concerns\RecordsActivity;
use Database\Factories\CalendarFilterFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * A schedule filter a member saved for themselves, e.g. "My unpaid daily
 * patients". The rules are applied by the schedule page; only the member who
 * saved a filter can see or change it.
 *
 * @property int $id
 * @property int $clinic_id
 * @property int $clinic_user_id
 * @property string $name
 * @property array{match: string, conditions: list<array{field: string, operator: string, values: list<int|string>}>} $rules
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
#[Fillable(['name', 'rules'])]
class CalendarFilter extends Model
{
    /** @use HasFactory<CalendarFilterFactory> */
    use HasFactory, RecordsActivity;

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'rules' => 'array',
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
     * The member who saved the filter.
     *
     * @return BelongsTo<ClinicUser, $this>
     */
    public function clinicUser(): BelongsTo
    {
        return $this->belongsTo(ClinicUser::class);
    }

    public function activityEntity(): string
    {
        return 'calendar_filter';
    }

    public function activityLabel(): string
    {
        return $this->name;
    }
}
