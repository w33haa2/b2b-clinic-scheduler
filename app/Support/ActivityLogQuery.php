<?php

namespace App\Support;

use App\Enums\ActivityCategory;
use App\Models\ActivityLog;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Date;
use Illuminate\Validation\Rule;

/**
 * The filters shared by the clinic and internal activity log endpoints.
 */
class ActivityLogQuery
{
    /**
     * @param  Builder<ActivityLog>  $query
     * @param  array<string, mixed>  $filters
     * @return Builder<ActivityLog>
     */
    public static function apply(Builder $query, array $filters): Builder
    {
        return $query
            ->when($filters['category'] ?? null, fn (Builder $query, string $category) => $query->inCategory(ActivityCategory::from($category)))
            ->when($filters['user_id'] ?? null, fn (Builder $query, int|string $userId) => $query->where('user_id', $userId))
            ->when($filters['from'] ?? null, fn (Builder $query, string $from) => $query->where('created_at', '>=', Date::parse($from)->utc()))
            ->when($filters['to'] ?? null, fn (Builder $query, string $to) => $query->where('created_at', '<', Date::parse($to)->utc()))
            ->when($filters['search'] ?? null, fn (Builder $query, string $search) => $query->where(fn (Builder $query) => $query
                ->whereLike('subject_label', "%{$search}%")
                ->orWhereLike('action', "%{$search}%")
                ->orWhereHas('user', fn (Builder $query) => $query
                    ->whereLike('name', "%{$search}%")
                    ->orWhereLike('email', "%{$search}%"))))
            ->with('user')
            ->orderByDesc('created_at')
            ->orderByDesc('id');
    }

    /**
     * Validation rules for the shared filters.
     *
     * @return array<string, array<int, mixed>>
     */
    public static function rules(): array
    {
        return [
            'category' => ['sometimes', 'string', Rule::enum(ActivityCategory::class)],
            'user_id' => ['sometimes', 'integer'],
            'from' => ['sometimes', 'date'],
            'to' => ['sometimes', 'date'],
            'search' => ['sometimes', 'string', 'max:255'],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:100'],
        ];
    }

    /**
     * Category options for filter dropdowns.
     *
     * @param  array<int, ActivityCategory>  $categories
     * @return list<array{value: string, label: string}>
     */
    public static function categoryOptions(array $categories): array
    {
        return array_map(fn (ActivityCategory $category): array => ['value' => $category->value, 'label' => $category->label()], array_values($categories));
    }
}
