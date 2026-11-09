<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Resources\ClinicResource;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class DashboardController extends Controller
{
    /**
     * Send each user to where they work: super admins to the internal tool,
     * members of a single clinic straight into it, everyone else to a
     * clinic picker.
     */
    public function __invoke(Request $request): Response|RedirectResponse
    {
        $user = $request->user();

        if ($user->is_super_admin) {
            return to_route('admin.internal.clinics.index');
        }

        $clinics = $user->clinics()->orderBy('name')->get();

        if ($clinics->count() === 1) {
            return to_route('admin.clinics.home', $clinics->first());
        }

        return Inertia::render('admin/Dashboard', [
            'clinics' => ClinicResource::collection($clinics),
        ]);
    }
}
