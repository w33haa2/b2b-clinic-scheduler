<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Resources\ClinicResource;
use App\Models\Clinic;
use Inertia\Inertia;
use Inertia\Response;

class InternalClinicPageController extends Controller
{
    /**
     * Show one clinic in the internal tool. Users and roles load from the internal API.
     */
    public function show(Clinic $clinic): Response
    {
        return Inertia::render('admin/internal/ClinicShow', [
            'clinic' => new ClinicResource($clinic),
        ]);
    }
}
