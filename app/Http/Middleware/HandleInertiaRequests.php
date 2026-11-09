<?php

namespace App\Http\Middleware;

use App\Models\Clinic;
use App\Support\ClinicContext;
use Illuminate\Http\Request;
use Inertia\Middleware;

class HandleInertiaRequests extends Middleware
{
    /**
     * The root template that's loaded on the first page visit.
     *
     * @see https://inertiajs.com/server-side-setup#root-template
     *
     * @var string
     */
    protected $rootView = 'app';

    /**
     * Determines the current asset version.
     *
     * @see https://inertiajs.com/asset-versioning
     */
    public function version(Request $request): ?string
    {
        return parent::version($request);
    }

    /**
     * Define the props that are shared by default.
     *
     * @see https://inertiajs.com/shared-data
     *
     * @return array<string, mixed>
     */
    public function share(Request $request): array
    {
        return [
            ...parent::share($request),
            'name' => config('app.name'),
            'auth' => [
                'user' => $request->user(),
                'clinics' => fn () => $request->user()?->clinics()->orderBy('name')->get(['clinics.id', 'clinics.name'])
                    ->map(fn (Clinic $clinic): array => ['id' => $clinic->id, 'name' => $clinic->name]) ?? [],
            ],
            'clinicContext' => fn () => $this->clinicContext(),
            'sidebarOpen' => ! $request->hasCookie('sidebar_state') || $request->cookie('sidebar_state') === 'true',
        ];
    }

    /**
     * The clinic the current page belongs to, with the member's access. Null
     * outside the clinic area. Resolved lazily, after EnsureClinicMember ran.
     *
     * @return array{clinic: array{id: int, name: string, timezone: string}, member: array{id: int, is_admin: bool, permissions: list<string>}}|null
     */
    protected function clinicContext(): ?array
    {
        $member = app(ClinicContext::class)->memberOrNull();

        if ($member === null) {
            return null;
        }

        return [
            'clinic' => [
                'id' => $member->clinic->id,
                'name' => $member->clinic->name,
                'timezone' => $member->clinic->timezone,
            ],
            'member' => [
                'id' => $member->id,
                'is_admin' => $member->isAdmin(),
                'permissions' => array_values($member->permissionNames()->all()),
            ],
        ];
    }
}
