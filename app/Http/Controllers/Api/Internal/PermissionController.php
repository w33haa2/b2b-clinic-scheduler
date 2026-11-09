<?php

namespace App\Http\Controllers\Api\Internal;

use App\Http\Controllers\Controller;
use App\Http\Resources\PermissionResource;
use App\Models\ClinicPermission;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class PermissionController extends Controller
{
    /**
     * List every grantable clinic permission.
     */
    public function index(): AnonymousResourceCollection
    {
        return PermissionResource::collection(ClinicPermission::query()->orderBy('name')->get());
    }
}
