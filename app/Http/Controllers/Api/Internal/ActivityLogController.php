<?php

namespace App\Http\Controllers\Api\Internal;

use App\Enums\ActivityCategory;
use App\Http\Controllers\Controller;
use App\Http\Resources\ActivityLogResource;
use App\Models\ActivityLog;
use App\Models\Clinic;
use App\Support\ActivityLogQuery;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Validation\Rule;

class ActivityLogController extends Controller
{
    /**
     * List every activity log entry on the platform, newest first.
     *
     * `level=platform` keeps entries that belong to no clinic (sign-ins, user
     * accounts, API tokens); `level=clinic` keeps clinic entries, optionally
     * for one `clinic_id`. The other filters match the clinic endpoint.
     */
    public function index(Request $request): AnonymousResourceCollection
    {
        $filters = $request->validate([
            ...ActivityLogQuery::rules(),
            'level' => ['sometimes', 'string', Rule::in(['platform', 'clinic'])],
            'clinic_id' => ['sometimes', 'integer'],
        ]);

        $query = ActivityLog::query()
            ->with('clinic')
            ->when(($filters['level'] ?? null) === 'platform', fn (Builder $query) => $query->whereNull('clinic_id'))
            ->when(($filters['level'] ?? null) === 'clinic', fn (Builder $query) => $query->whereNotNull('clinic_id'))
            ->when($filters['clinic_id'] ?? null, fn (Builder $query, int|string $clinicId) => $query->where('clinic_id', $clinicId));

        $logs = ActivityLogQuery::apply($query, $filters)->paginate($filters['per_page'] ?? 30);

        return ActivityLogResource::collection($logs)->additional(['meta' => ['filters' => [
            'categories' => ActivityLogQuery::categoryOptions(ActivityCategory::cases()),
            'clinics' => Clinic::withTrashed()->orderBy('name')->get(['id', 'name'])
                ->map(fn (Clinic $clinic): array => ['id' => $clinic->id, 'name' => $clinic->name]),
        ]]]);
    }
}
