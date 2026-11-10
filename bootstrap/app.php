<?php

use App\Http\Middleware\EnsureClinicAdmin;
use App\Http\Middleware\EnsureClinicMember;
use App\Http\Middleware\EnsureClinicPermission;
use App\Http\Middleware\EnsureSuperAdmin;
use App\Http\Middleware\HandleAppearance;
use App\Http\Middleware\HandleInertiaRequests;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Middleware\AddLinkHeadersForPreloadedAssets;
use Illuminate\Http\Request;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->encryptCookies(except: ['appearance', 'sidebar_state']);

        /*
         * Behind a managed load balancer (Render, Cloud, any PaaS) the TLS
         * ends at the proxy, so without this Laravel builds http:// URLs,
         * sees every request as insecure, and reads the proxy's address as
         * the client IP — which would put every visitor in the same rate
         * limit bucket and write the wrong IP into the activity log.
         *
         * The proxy's address is not known ahead of time, so all proxies are
         * trusted. That is safe only because nothing reaches PHP without
         * passing through that proxy first.
         */
        $middleware->trustProxies(at: '*');

        $middleware->statefulApi();

        $middleware->alias([
            'clinic.member' => EnsureClinicMember::class,
            'clinic.permission' => EnsureClinicPermission::class,
            'clinic.admin' => EnsureClinicAdmin::class,
            'super-admin' => EnsureSuperAdmin::class,
        ]);

        $middleware->web(append: [
            HandleAppearance::class,
            HandleInertiaRequests::class,
            AddLinkHeadersForPreloadedAssets::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*') || $request->expectsJson(),
        );
    })->create();
