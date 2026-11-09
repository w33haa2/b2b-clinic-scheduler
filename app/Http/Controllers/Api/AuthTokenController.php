<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\StoreTokenRequest;
use App\Models\User;
use App\Support\ActivityLogger;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;
use Laravel\Sanctum\PersonalAccessToken;
use Laravel\Sanctum\TransientToken;

class AuthTokenController extends Controller
{
    /**
     * Issue an API token.
     *
     * Exchange an email and password for a bearer token. Send it as
     * `Authorization: Bearer <token>` on every other request.
     *
     * @unauthenticated
     */
    public function store(StoreTokenRequest $request): JsonResponse
    {
        $user = User::query()->where('email', $request->validated('email'))->first();

        if ($user === null || ! Hash::check($request->validated('password'), $user->password)) {
            throw ValidationException::withMessages([
                'email' => __('auth.failed'),
            ]);
        }

        $token = $user->createToken($request->validated('device_name'));

        app(ActivityLogger::class)->log('api_token.created', $token->accessToken, label: $token->accessToken->name, actor: $user);

        return response()->json(['token' => $token->plainTextToken], 201);
    }

    /**
     * Revoke the current API token.
     *
     * Requests authenticated by the browser session cookie have no token to revoke.
     */
    public function destroy(Request $request): Response
    {
        /** @var PersonalAccessToken|TransientToken $token */
        $token = $request->user()->currentAccessToken();

        if ($token instanceof PersonalAccessToken) {
            app(ActivityLogger::class)->log('api_token.revoked', $token, label: $token->name);
            $token->delete();
        }

        return response()->noContent();
    }
}
