<?php

namespace App\Http\Controllers\Api\Clinic;

use App\Enums\ActivityCategory;
use App\Http\Controllers\Controller;
use App\Http\Resources\ActivityLogResource;
use App\Models\ActivityLog;
use App\Models\Clinic;
use App\Models\User;
use App\Support\ActivityLogQuery;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class ActivityLogController extends Controller
{
    /**
     * List the clinic's activity log, newest first.
     *
     * Filter by `category`, the acting `user_id`, a time window (`from` / `to`)
     * or `search` (record name, action, or the actor's name or email).
     * `meta.filters` lists the categories and the people who appear in the log.
     */
    public function index(Request $request, Clinic $clinic): AnonymousResourceCollection
    {
        $filters = $request->validate(ActivityLogQuery::rules());

        $logs = ActivityLogQuery::apply(ActivityLog::query()->whereBelongsTo($clinic), $filters)
            ->paginate($filters['per_page'] ?? 30);

        $actorIds = ActivityLog::query()->whereBelongsTo($clinic)->whereNotNull('user_id')->distinct()->pluck('user_id');

        return ActivityLogResource::collection($logs)->additional(['meta' => ['filters' => [
            // Sign-ins and accounts are platform-level, never in a clinic's log.
            'categories' => ActivityLogQuery::categoryOptions(
                array_filter(ActivityCategory::cases(), fn (ActivityCategory $category): bool => $category !== ActivityCategory::Account),
            ),
            'actors' => User::query()->whereKey($actorIds)->orderBy('name')->get(['id', 'name', 'is_super_admin'])
                ->map(fn (User $user): array => ['id' => $user->id, 'name' => $user->name, 'is_super_admin' => $user->is_super_admin]),
        ]]]);
    }
}
