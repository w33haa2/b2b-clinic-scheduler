<?php

namespace App\Http\Middleware;

use App\Models\Clinic;
use App\Support\ClinicContext;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Lets the request through only when the user is a member of the {clinic}
 * in the URL. Non-members get 404 so they cannot learn the clinic exists.
 */
class EnsureClinicMember
{
    public function __construct(private ClinicContext $context) {}

    /**
     * Handle an incoming request.
     *
     * @param  Closure(Request): (Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $clinic = $request->route('clinic');

        $member = $clinic instanceof Clinic
            ? $request->user()?->clinicUsers()->whereBelongsTo($clinic)->with('clinicRoles')->first()
            : null;

        abort_if($member === null, 404);

        $member->setRelation('clinic', $clinic);
        $this->context->setMember($member);

        return $next($request);
    }
}
