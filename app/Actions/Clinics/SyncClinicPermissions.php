<?php

namespace App\Actions\Clinics;

use App\Http\Middleware\EnsureClinicPermission;
use App\Models\ClinicPermission;
use Illuminate\Routing\Route;
use Illuminate\Routing\Router;
use Illuminate\Support\Collection;

/**
 * Makes the clinic_permissions table match the clinic API routes guarded by
 * the "clinic.permission" middleware: one row per route name.
 */
class SyncClinicPermissions
{
    public function __construct(private Router $router) {}

    /**
     * Insert missing permissions and delete those whose route no longer exists.
     *
     * @return array{added: list<string>, removed: list<string>}
     */
    public function handle(): array
    {
        $routeNames = $this->permissionRouteNames();
        $existing = ClinicPermission::query()->pluck('name')->map(fn (mixed $name): string => (string) $name);

        $added = $routeNames->diff($existing)->values();
        $removed = $existing->diff($routeNames)->values();

        $now = now();

        ClinicPermission::query()->insert(
            $added->map(fn (string $name): array => ['name' => $name, 'created_at' => $now, 'updated_at' => $now])->all(),
        );

        ClinicPermission::query()->whereIn('name', $removed)->delete();

        return ['added' => array_values($added->all()), 'removed' => array_values($removed->all())];
    }

    /**
     * The names of every route protected by the permission middleware.
     *
     * @return Collection<int, string>
     */
    public function permissionRouteNames(): Collection
    {
        return collect($this->router->getRoutes()->getRoutes())
            ->filter(fn (Route $route): bool => $route->getName() !== null && collect($route->gatherMiddleware())
                ->contains(fn (mixed $middleware): bool => in_array($middleware, ['clinic.permission', EnsureClinicPermission::class], true)))
            ->map(fn (Route $route): string => (string) $route->getName())
            ->unique()
            ->sort()
            ->values();
    }
}
