<?php

namespace App\Models;

use App\Enums\ActivityCategory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\MassPrunable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Support\Carbon;

/**
 * One recorded action. Append-only: rows are written by App\Support\ActivityLogger
 * and never edited. Old rows are pruned after `activity.retention_days`.
 *
 * @property int $id
 * @property int|null $clinic_id
 * @property int|null $user_id
 * @property string $action
 * @property string|null $subject_type
 * @property int|null $subject_id
 * @property string|null $subject_label
 * @property array<string, array{old: mixed, new: mixed}>|null $changes
 * @property array<string, mixed>|null $context
 * @property string|null $ip_address
 * @property Carbon $created_at
 */
#[Fillable(['clinic_id', 'user_id', 'action', 'subject_type', 'subject_id', 'subject_label', 'changes', 'context', 'ip_address'])]
class ActivityLog extends Model
{
    use MassPrunable;

    /**
     * Rows are never updated, so there is no updated_at column.
     */
    public const UPDATED_AT = null;

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'changes' => 'array',
            'context' => 'array',
            'created_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<Clinic, $this>
     */
    public function clinic(): BelongsTo
    {
        return $this->belongsTo(Clinic::class)->withTrashed();
    }

    /**
     * The user who performed the action.
     *
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * @return MorphTo<Model, $this>
     */
    public function subject(): MorphTo
    {
        return $this->morphTo();
    }

    public function category(): ?ActivityCategory
    {
        return ActivityCategory::forAction($this->action);
    }

    /**
     * Actions in the given category.
     *
     * @param  Builder<ActivityLog>  $query
     */
    #[Scope]
    protected function inCategory(Builder $query, ActivityCategory $category): void
    {
        $query->where(function (Builder $query) use ($category): void {
            foreach ($category->prefixes() as $prefix) {
                $query->orWhereLike('action', "{$prefix}.%");
            }
        });
    }

    /**
     * Rows older than the retention period are deleted by `model:prune`.
     *
     * @return Builder<static>
     */
    public function prunable(): Builder
    {
        return static::query()->where('created_at', '<', now()->subDays((int) config('activity.retention_days')));
    }
}
