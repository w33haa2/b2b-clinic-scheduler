<?php

namespace App\Http\Controllers\Api\Clinic;

use App\Http\Controllers\Controller;
use App\Http\Resources\PermissionResource;
use App\Models\Clinic;
use App\Models\ClinicPermission;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class PermissionController extends Controller
{
    /**
     * List the permissions that can be granted to a role.
     *
     * Each permission is a clinic API route name. Role and member management
     * are not listed: they are reserved for the Clinic Admin role.
     */
    public function index(Clinic $clinic): AnonymousResourceCollection
    {
        return PermissionResource::collection(ClinicPermission::query()->orderBy('name')->get());
    }
}
