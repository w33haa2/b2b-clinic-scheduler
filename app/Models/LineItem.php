<?php

namespace App\Models;

use App\Models\Concerns\RecordsActivity;
use Database\Factories\LineItemFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Carbon;

/**
 * A billable item in a clinic's catalog (e.g. "Consultation").
 *
 * @property int $id
 * @property int $clinic_id
 * @property string $item_code
 * @property string $name
 * @property string $price
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property Carbon|null $deleted_at
 */
#[Fillable(['item_code', 'name', 'price'])]
class LineItem extends Model
{
    /** @use HasFactory<LineItemFactory> */
    use HasFactory, RecordsActivity, SoftDeletes;

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'price' => 'decimal:2',
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
     * @return HasMany<SessionLineItem, $this>
     */
    public function sessionLineItems(): HasMany
    {
        return $this->hasMany(SessionLineItem::class);
    }

    public function activityEntity(): string
    {
        return 'service';
    }

    public function activityLabel(): string
    {
        return "{$this->name} ({$this->item_code})";
    }
}
