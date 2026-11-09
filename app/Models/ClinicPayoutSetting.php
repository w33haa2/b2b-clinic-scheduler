<?php

namespace App\Models;

use App\Models\Concerns\RecordsActivity;
use Brick\Math\BigDecimal;
use Database\Factories\ClinicPayoutSettingFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * How a clinic splits what it bills between itself and the member who held
 * the session. One row per clinic, created on demand with the defaults.
 *
 * @property int $id
 * @property int $clinic_id
 * @property string $worker_share_percentage
 * @property bool $include_pending_payments
 * @property bool $require_attendance
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
#[Fillable(['worker_share_percentage', 'include_pending_payments', 'require_attendance'])]
class ClinicPayoutSetting extends Model
{
    /** @use HasFactory<ClinicPayoutSettingFactory> */
    use HasFactory, RecordsActivity;

    /**
     * The share a clinic starts with when it has never configured one.
     */
    public const DEFAULT_WORKER_SHARE = '60.00';

    /**
     * The model's default values for attributes.
     *
     * @var array<string, mixed>
     */
    protected $attributes = [
        'worker_share_percentage' => self::DEFAULT_WORKER_SHARE,
        'include_pending_payments' => true,
        'require_attendance' => false,
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'worker_share_percentage' => 'decimal:2',
            'include_pending_payments' => 'boolean',
            'require_attendance' => 'boolean',
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
     * What is left for the clinic once the member has taken their share.
     */
    public function clinicSharePercentage(): string
    {
        return (string) BigDecimal::of('100')->minus($this->worker_share_percentage)->toScale(2);
    }

    public function activityEntity(): string
    {
        return 'payout_setting';
    }

    public function activityLabel(): string
    {
        return "{$this->worker_share_percentage}% to staff";
    }
}
