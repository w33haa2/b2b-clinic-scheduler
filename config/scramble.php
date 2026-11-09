<?php

/*
|--------------------------------------------------------------------------
| Scramble (OpenAPI / Swagger docs)
|--------------------------------------------------------------------------
|
| dedoc/scramble is a dev-only dependency, so the docs at /docs/api exist
| only in development. They are also restricted to APP_ENV=local. Only the
| keys that differ from the package defaults are listed here; the package
| merges in the rest. Keep this file free of Scramble class constants: it is
| still loaded in production, where the package is not installed.
|
*/

return [
    'api_path' => 'api',

    'info' => [
        'version' => env('API_VERSION', '0.1.0'),
        'description' => <<<'MD'
            Clinic booking API. See `docs/api.md` in the repository for the full guide.

            **Authentication.** Call `POST /tokens` with an email, password and device name, then send
            `Authorization: Bearer <token>`. The browser app can instead use its session cookie.

            **Clinic API** (`/clinics/{clinic}/...`): the user must be a member of the clinic (otherwise 404),
            and each endpoint requires the permission named after its route (otherwise 403). Clinic Admins
            hold every permission.

            **Internal API** (`/internal/...`): super admins only.
            MD,
    ],

    'ui' => [
        'title' => 'Clinic App API',
    ],

    'security_strategy' => 'Dedoc\Scramble\SecurityDocumentation\MiddlewareAuthSecurityStrategy',
];
