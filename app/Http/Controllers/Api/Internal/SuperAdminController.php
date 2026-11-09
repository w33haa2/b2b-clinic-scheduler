<?php

namespace App\Http\Controllers\Api\Internal;

use App\Http\Controllers\Controller;
use App\Http\Requests\Internal\SuperAdminRequest;
use App\Http\Resources\UserResource;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;
use Illuminate\Validation\ValidationException;

class SuperAdminController extends Controller
{
    /**
     * List super admins.
     */
    public function index(Request $request): AnonymousResourceCollection
    {
        $perPage = $request->validate(['per_page' => ['sometimes', 'integer', 'min:1', 'max:100']])['per_page'] ?? 25;

        return UserResource::collection(
            User::query()->where('is_super_admin', true)->orderBy('name')->orderBy('id')->paginate($perPage),
        );
    }

    /**
     * Create a super admin.
     */
    public function store(SuperAdminRequest $request): UserResource
    {
        $user = new User($request->validated());
        $user->forceFill(['is_super_admin' => true, 'email_verified_at' => now()])->save();

        return new UserResource($user);
    }

    /**
     * Get a super admin.
     */
    public function show(User $user): UserResource
    {
        return new UserResource($this->superAdmin($user));
    }

    /**
     * Update a super admin.
     */
    public function update(SuperAdminRequest $request, User $user): UserResource
    {
        $this->superAdmin($user)->update($request->validated());

        return new UserResource($user);
    }

    /**
     * Delete a super admin.
     *
     * You cannot delete your own account through this endpoint.
     */
    public function destroy(Request $request, User $user): Response
    {
        if ($this->superAdmin($user)->is($request->user())) {
            throw ValidationException::withMessages([
                'user' => __('You cannot delete your own super admin account.'),
            ]);
        }

        $user->delete();

        return response()->noContent();
    }

    /**
     * Only super admins are managed here; any other user ID is not found.
     */
    private function superAdmin(User $user): User
    {
        abort_unless($user->is_super_admin, 404);

        return $user;
    }
}
