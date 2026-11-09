<?php

namespace App\Http\Middleware;

use App\Support\ClinicContext;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Restricts a clinic route to members holding the Clinic Admin role. Used for
 * role and member management, which are never grantable to other roles so a
 * member cannot give themselves more access.
 */
class EnsureClinicAdmin
{
    public function __construct(private ClinicContext $context) {}

    /**
     * Handle an incoming request.
     *
     * @param  Closure(Request): (Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        abort_unless($this->context->member()->isAdmin(), 403);

        return $next($request);
    }
}
