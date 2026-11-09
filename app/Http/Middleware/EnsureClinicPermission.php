<?php

namespace App\Http\Middleware;

use App\Support\ClinicContext;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Allows the request only when the member's roles grant the permission named
 * after the current route (e.g. "clinic.patients.store"). Clinic Admins pass
 * every check. Every API route using this middleware without a parameter
 * becomes a grantable permission through `php artisan clinic:sync-permissions`.
 *
 * Web pages pass the permission explicitly instead, e.g.
 * `clinic.permission:clinic.sessions.index` guards the Schedule page with the
 * permission of the API endpoint it reads. Those routes are not permissions
 * themselves.
 */
class EnsureClinicPermission
{
    public function __construct(private ClinicContext $context) {}

    /**
     * Handle an incoming request.
     *
     * @param  Closure(Request): (Response)  $next
     */
    public function handle(Request $request, Closure $next, ?string $permission = null): Response
    {
        $permission ??= (string) $request->route()?->getName();

        abort_unless($this->context->member()->hasPermission($permission), 403);

        return $next($request);
    }
}
