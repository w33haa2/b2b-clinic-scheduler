<?php

namespace App\Models;

use App\Enums\DiscountTarget;
use App\Enums\DiscountType;
use App\Models\Concerns\RecordsActivity;
use Database\Factories\DiscountFactory;
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
 * Money taken off a billed item. A clinic-wide discount (clinic_user_id null)
 * is created by a Clinic Admin and everyone can use it; a personal discount
 * belongs to one member and only they can see or use it. The target says
 * whose cut pays for it.
 *
 * @property int $id
 * @property int $clinic_id
 * @property int|null $clinic_user_id
 * @property string $name
 * @property DiscountType $discount_type_id
 * @property DiscountTarget $discount_target_id
 * @property string $value
 * @property bool $is_active
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property Carbon|null $deleted_at
 */
#[Fillable(['clinic_user_id', 'name', 'discount_type_id', 'discount_target_id', 'value', 'is_active'])]
class Discount extends Model
{
    /** @use HasFactory<DiscountFactory> */
    use HasFactory, RecordsActivity, SoftDeletes;

    /**
     * The model's default values for attributes.
     *
     * @var array<string, mixed>
     */
    protected $attributes = [
        'is_active' => true,
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'discount_type_id' => DiscountType::class,
            'discount_target_id' => DiscountTarget::class,
            'value' => 'decimal:2',
            'is_active' => 'boolean',
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
     * The member who owns it, or null when it is clinic-wide.
     *
     * @return BelongsTo<ClinicUser, $this>
     */
    public function clinicUser(): BelongsTo
    {
        return $this->belongsTo(ClinicUser::class)->withTrashed();
    }

    /**
     * @return HasMany<SessionLineItem, $this>
     */
    public function sessionLineItems(): HasMany
    {
        return $this->hasMany(SessionLineItem::class);
    }

    /**
     * Determine whether this discount belongs to the whole clinic.
     */
    public function isClinicWide(): bool
    {
        return $this->clinic_user_id === null;
    }

    /**
     * The discounts a member may use: the clinic's own plus their personal ones.
     *
     * @param  Builder<Discount>  $query
     */
    #[Scope]
    protected function visibleTo(Builder $query, ClinicUser $member): void
    {
        $query->where(fn (Builder $query) => $query
            ->whereNull('clinic_user_id')
            ->orWhere('clinic_user_id', $member->id));
    }

    /**
     * What this discount takes off the given gross amount.
     */
    public function deductionFrom(string $gross): string
    {
        return $this->discount_type_id->deductionFrom($gross, $this->value);
    }

    public function activityEntity(): string
    {
        return 'discount';
    }

    public function activityLabel(): string
    {
        return $this->name;
    }

    /**
     * @return array<string, mixed>
     */
    public function activityContext(): array
    {
        return ['scope' => $this->isClinicWide() ? 'clinic-wide' : 'personal'];
    }
}
