# Architecture

## Stack

| Layer                    | Technology                                            | Version    |
| ------------------------ | ----------------------------------------------------- | ---------- |
| Language                 | PHP                                                   | 8.5        |
| Framework                | Laravel                                               | 13.x       |
| Auth backend             | Laravel Fortify                                       | 1.x        |
| Server ↔ client bridge   | Inertia.js (`inertia-laravel` / `@inertiajs/vue3`)    | 3.x        |
| UI framework             | Vue (Composition API, `<script setup lang="ts">`)     | 3.5        |
| Components               | shadcn-vue (on top of reka-ui)                        | —          |
| Styling                  | Tailwind CSS                                          | 4.x        |
| State                    | Pinia                                                 | 3.x        |
| Typed routes             | Laravel Wayfinder                                     | 0.1.x      |
| API authentication       | Laravel Sanctum (tokens + SPA session cookie)         | 4.x        |
| API docs (dev only)      | Scramble (OpenAPI / Swagger)                          | 0.13       |
| PDF text extraction      | smalot/pdfparser (pure PHP, no binaries)              | 2.13       |
| Calendar                 | FullCalendar (`@fullcalendar/vue3`)                   | 6.1        |
| Animations               | animate.css, lottie-web (light build)                 | 4.1 / 5.13 |
| Build tool               | Vite+ (`vite-plus`, the `vp` CLI)                     | 1.4        |
| Tests                    | Pest                                                  | 5.x        |
| Database                 | MySQL in Docker (SQLite for tests and non-Docker dev) | 8.4        |
| Cache / queue / sessions | Redis in Docker                                       | 7          |

The project began from the official Laravel Vue starter kit and keeps its
conventions.

## How a request flows

1. Nginx (the `web` container) serves files from `public/` directly. Anything
   else goes to PHP-FPM (the `app` container) through `public/index.php`.
2. Laravel matches a route. Pages are returned with `Inertia::render('<component>')`
   (or the `Route::inertia()` shortcut).
3. On the first visit, Inertia renders `resources/views/app.blade.php` with the
   page data embedded. In development, Vite also server-side renders the page.
   After that, navigation happens client-side over XHR, and the server returns
   only JSON page data.
4. The Vue app (`resources/js/app.ts`) resolves `<component>` to
   `resources/js/pages/<component>.vue` and wraps it in a layout (see
   [frontend.md](frontend.md#layouts)).

## The JSON API: `/api`

Clinic data (patients, sessions, billing, invoices, roles) is served by a
JSON API in `routes/api.php`, split into the **clinic API**
(`/api/clinics/{clinic}/…`, for clinic members, checked per endpoint
against role permissions) and the **internal API** (`/api/internal/…`, super
admins only). See [api.md](api.md) for access rules and endpoints and
[domain-model.md](domain-model.md) for the data model.

The Vue pages use this same API from the browser (session cookie + XSRF
token); the web routes only render page shells. See
[frontend.md](frontend.md#how-pages-get-their-data).

## The two web areas: `/` and `/admin`

The Inertia app is split into two areas by URL prefix and by page directory:

|             | Public site                  | Admin area                                            |
| ----------- | ---------------------------- | ----------------------------------------------------- |
| URL         | `/`                          | `/admin/*`                                            |
| Routes file | `routes/web.php`             | `routes/admin.php`, `routes/settings.php`             |
| Vue pages   | `resources/js/pages/public/` | `resources/js/pages/admin/`                           |
| Layout      | `layouts/PublicLayout.vue`   | `AppLayout` (sidebar), `AuthLayout`, `SettingsLayout` |
| Access      | Anyone                       | Authenticated users (`auth`, `verified` middleware)   |

`routes/web.php` registers the public routes and mounts everything in
`routes/admin.php` under the `admin` prefix:

```php
Route::inertia('/', 'public/Home')->name('home');

Route::prefix('admin')->group(__DIR__.'/admin.php');
```

Fortify's authentication routes use the same prefix through
`config/fortify.php` (`'prefix' => 'admin'`). After login, users land on `'home' => '/admin'`.

`/admin` (`Admin\DashboardController`) sends each user to where they work:

| User                               | Goes to                                                                                          |
| ---------------------------------- | ------------------------------------------------------------------------------------------------ |
| Super admin                        | `/admin/internal/clinics` (internal tool)                                                        |
| Member of one clinic               | `/admin/clinics/{clinic}`, which opens the first page their roles allow (`ClinicHomeController`) |
| Member of several clinics, or none | `admin/Dashboard`: a clinic picker, or an empty state                                            |

**Clinic pages** (`/admin/clinics/{clinic}/…`) use the same `clinic.member`
middleware as the API, so non-members get 404. Each page also requires the
permission of the API list it shows, passed explicitly:
`clinic.permission:clinic.sessions.index`. Routes with an explicit permission
are not themselves grantable permissions. **Internal pages**
(`/admin/internal/…`) use the `super-admin` middleware.

A few clinic pages show the clinic's own books and configuration rather than
the work done in it: Receivables and Payout settings. They are registered from
`ClinicHomeController::ADMIN_PAGES` and guarded by `clinic.admin` instead of a
permission, so a role can never grant them — only a Clinic Admin may open
them. `ClinicHomeController` falls back to the first of these when a member's
roles give them no ordinary page but they are an admin.

### Current routes

| Method           | URL                                                                                                                                                                                                                     | Name                                       | What                                                  |
| ---------------- | ----------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- | ------------------------------------------ | ----------------------------------------------------- |
| GET              | `/`                                                                                                                                                                                                                     | `home`                                     | Public home page (`public/Home`)                      |
| GET/POST         | `/patient-form/{token}`                                                                                                                                                                                                 | `patient-form.show`, `patient-form.submit` | Patient intake link (no sign-in; see api.md)          |
| GET              | `/admin`                                                                                                                                                                                                                | `dashboard`                                | Redirect to the user's area, or the clinic picker     |
| GET              | `/admin/clinics/{clinic}`                                                                                                                                                                                               | `admin.clinics.home`                       | First clinic page the member may open                 |
| GET              | `/admin/clinics/{clinic}/schedule`, `/patients`, `/progress-notes`, `/patient-form`, `/services`, `/discounts`, `/payments`, `/invoices`, `/transactions`, `/attendance`, `/payouts`, `/members`, `/roles`, `/activity` | `admin.clinics.*`                          | Clinic workspace pages (`admin/clinic/*`)             |
| GET              | `/admin/clinics/{clinic}/receivables`, `/admin/clinics/{clinic}/payout-settings`                                                                                                                                        | `admin.clinics.*`                          | Clinic Admin-only clinic pages (`admin/clinic/*`)     |
| GET              | `/admin/internal/clinics`, `/admin/internal/clinics/{clinic}`, `/admin/internal/super-admins`                                                                                                                           | `admin.internal.*`                         | Internal tool (`admin/internal/*`)                    |
| GET/POST         | `/admin/invitations/{token}`                                                                                                                                                                                            | `invitations.show`, `invitations.accept`   | Clinic invitation link (no sign-in; see api.md)       |
| GET/POST         | `/admin/login`                                                                                                                                                                                                          | `login`, `login.store`                     | Login                                                 |
| POST             | `/admin/logout`                                                                                                                                                                                                         | `logout`                                   | Logout (redirects to `/`)                             |
| GET/POST         | `/admin/forgot-password`                                                                                                                                                                                                | `password.request`, `password.email`       | Request reset link                                    |
| GET/POST         | `/admin/reset-password/{token}`                                                                                                                                                                                         | `password.reset`, `password.update`        | Reset password                                        |
| GET/POST         | `/admin/user/confirm-password`                                                                                                                                                                                          | `password.confirm`, …                      | Confirm password                                      |
| GET/PATCH/DELETE | `/admin/settings/profile`                                                                                                                                                                                               | `profile.*`                                | Profile settings                                      |
| GET              | `/admin/settings/security`                                                                                                                                                                                              | `security.edit`                            | Password settings                                     |
| PUT              | `/admin/settings/password`                                                                                                                                                                                              | `user-password.update`                     | Update password                                       |
| GET              | `/admin/settings/appearance`                                                                                                                                                                                            | `appearance.edit`                          | Light/dark theme                                      |
| GET              | `/up`                                                                                                                                                                                                                   | —                                          | Health check (used by the production `web` container) |

The API routes are listed in [api.md](api.md). Run
`php artisan route:list --except-vendor` for the live list. The clinic pages
are `Route::inertia` routes, whose controller lives in `vendor/`, so drop the
flag (or pass `--path=admin/clinics`) to see them.

Route names do not include `admin.`: the existing names (`dashboard`,
`login`, `profile.edit`, …) were kept so Wayfinder imports did not change. Use
the route name, not a hard-coded URL, when linking to a route
(`route('dashboard')` in PHP, `dashboard()` from `@/routes` in Vue).

## SEO

The public site is built to be indexed; the app is not.

| What                                                                                                            | Where                                                                                                                                                                     |
| --------------------------------------------------------------------------------------------------------------- | ------------------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| Title                                                                                                           | `<Head title>` in `pages/public/Home.vue` (app name appended by `app.ts`)                                                                                                 |
| Description, canonical, Open Graph, Twitter card, JSON-LD (Organization, WebSite, SoftwareApplication, FAQPage) | `PublicSiteController::home()` passes `seo` view data to `resources/views/app.blade.php`, so the tags are in the HTML even without SSR                                    |
| Share image                                                                                                     | `public/og-image.png` (1200×630, a render of the hero)                                                                                                                    |
| `robots.txt`                                                                                                    | Route, not a file. In production it disallows `/admin`, `/api` and `/docs` and lists the sitemap. Outside production it disallows everything, so staging is never indexed |
| `sitemap.xml`                                                                                                   | Route listing the public pages                                                                                                                                            |
| `noindex`                                                                                                       | `<meta name="robots" content="noindex, nofollow">` on every `/admin` page                                                                                                 |
| Server-rendered content                                                                                         | Inertia SSR: the Vite dev server in development, the `ssr` container in production ([docker.md](docker.md))                                                               |

Copy and contact settings live in `config/seo.php` (`SEO_DESCRIPTION`,
`CONTACT_EMAIL`, `SEO_TWITTER_SITE`). The FAQ answers are in
`PublicSiteController::faqs()`. `tests/Feature/PublicSiteTest.php` covers the tags,
`robots.txt` and the sitemap.

## Activity log and PDFs

- Every change is recorded in `activity_logs`, at clinic level (`clinic_id`) or
  platform level (sign-ins, accounts, API tokens). Clinic Admins read their
  clinic's log; super admins read everything. See [activity-log.md](activity-log.md).
- Invoice PDFs are rendered on the server with
  [dompdf](https://github.com/dompdf/dompdf) (pure PHP, no browser binary) from
  `resources/views/pdf/invoice.blade.php`
  (`Api\Clinic\InvoicePdfController`). Its font cache lives in
  `storage/framework/dompdf` because `vendor/` is read-only in production.
  The same setup renders the patient form from
  `resources/views/pdf/patient-form.blade.php`
  (`Api\Clinic\PatientFormPdfController`): blank, to hand out at the front
  desk, and filled in, as one patient's record. A filled download is written
  to the activity log.

## Authentication

- **Fortify** provides the login, logout, password reset and password
  confirmation endpoints. Its views are Inertia pages registered in
  `app/Providers/FortifyServiceProvider.php` (`admin/auth/*`).
- **Registration is disabled.** Only `Features::resetPasswords()` is enabled in
  `config/fortify.php`. Users are created through the API: super admins create
  clinics, clinic users and other super admins, and Clinic Admins add members.
  The seeder creates demo accounts (see [README](README.md)).
- **API** requests authenticate with Sanctum: a bearer token from
  `POST /api/tokens`, or the browser session cookie (see [api.md](api.md#authentication)).
- **Password reset emails are queued.** `User::sendPasswordResetNotification()`
  sends `App\Notifications\QueuedResetPassword` (the standard Laravel email,
  `ShouldQueue`), so the forgot-password request returns at once and the
  `queue` container sends the mail. In development it lands in Mailpit (see
  [docker.md](docker.md#checking-email-mailpit)).
- Login is rate-limited to 5 attempts per minute per email + IP.
- The `verified` middleware is on admin routes, but `User` does not implement
  `MustVerifyEmail` yet, so it has no effect today.

## Backend layout

```
app/
├── Actions/
│   ├── Billing/            UpdatePaymentStatus (the only writer of transactions), PrepayDailySession, SaveInvoice
│   ├── Clinics/            CreateClinic, Add/Remove member, roles, SyncClinicPermissions,
│   │                       Add/RemoveSessionPatient, SaveDailySession, ScheduleDailySessionDays,
│   │                       Enroll/RemoveDailySessionPatient, DeleteDailySession
│   ├── Patients/           CreateDefaultPatientForm, Save/DeletePatientFormField,
│   │                       ReorderPatientForm, SavePatientRecord, SubmitPatientForm (intake link)
│   ├── Payroll/            CalculatePayout (what a period owes, writing nothing),
│   │                       GeneratePayout, UpdatePayoutStatus
│   └── Fortify/            ResetUserPassword (Fortify action)
├── Concerns/               Shared validation rules (password, profile)
├── Enums/                  SessionStatus, PaymentStatus, TransactionType (lookup IDs), ClinicRoleKey, Sex,
│                           AttendanceStatus, PayoutStatus, DiscountType, DiscountTarget,
│                           ProgressRating, PatientFormFieldType
├── Http/
│   ├── Controllers/        PatientIntakeController (the patient intake link, outside /admin)
│   ├── Controllers/Api/    AuthToken, Me; Clinic/* (clinic API); Internal/* (super admin API)
│   ├── Controllers/Settings/  ProfileController, SecurityController
│   ├── Middleware/         EnsureClinicMember/Permission/Admin, EnsureSuperAdmin, Inertia, appearance
│   ├── Requests/           Api/, Clinic/, Internal/, Settings/ form requests
│   └── Resources/          JSON API resources
├── Models/                 User, Clinic, ClinicUser, ClinicRole, ClinicPermission, Patient,
│                           ClinicSession, ClinicSessionMember, DailySession, DailySessionPatient,
│                           Payment, LineItem, SessionLineItem, Transaction, Invoice, InvoiceItem,
│                           Discount, Attendance, Payout, PayoutItem, ClinicPayoutSetting,
│                           PatientFormField, PatientFieldValue, PatientFormInvitation,
│                           PatientProgressNote, PatientProgressMetric,
│                           CalendarFilter, ActivityLog
├── Providers/              AppServiceProvider (permission sync after migrate), FortifyServiceProvider
└── Support/                ClinicContext (the request's clinic membership), ActivityLogger,
                            HolderAvailability (busy/free time for plotting),
                            RevenueSplit (one billed item split between the member and the clinic),
                            PatientFormSchema (the clinic's form as validation rules),
                            FormExtraction/ (import drivers: text, CSV, JSON, HTML, DOCX, PDF)
routes/
├── web.php                 Public routes (incl. the patient intake link) + mounts admin.php under /admin
├── admin.php               Admin routes (requires settings.php)
├── settings.php            Admin account settings routes
├── api.php                 JSON API (clinic + internal)
└── console.php             Artisan commands (clinic:sync-permissions, clinic:schedule-daily-sessions) / schedule
```

### Shared Inertia props

`HandleInertiaRequests::share()` sends these to every page:

| Prop            | Value                                                                                                         |
| --------------- | ------------------------------------------------------------------------------------------------------------- |
| `name`          | `config('app.name')`                                                                                          |
| `auth.user`     | The logged-in user, or `null`                                                                                 |
| `sidebarOpen`   | Admin sidebar state, from the `sidebar_state` cookie                                                          |
| `auth.clinics`  | `[{id, name}]` of the clinics the user belongs to (clinic switcher)                                           |
| `clinicContext` | On clinic pages only: `{clinic: {id, name, timezone}, member: {id, is_admin, permissions}}`; otherwise `null` |

## Testing

- Pest feature tests live in `tests/Feature`. Every test uses `RefreshDatabase`
  against in-memory SQLite (configured in `phpunit.xml`).
- `tests/Feature/Api/` covers the API: tenant isolation, permissions, the
  business rules (session overlaps, status and payment transitions, billing
  locks, invoice numbering) and the internal API. Clinic factories create the
  built-in roles automatically. Use `ClinicUser::factory()->admin()` /
  `->worker()` / `->withRole($role)` and `Sanctum::actingAs($member->user)`.
  The permission table is filled automatically when the test database migrates.
  It also covers the newer modules: payout generation and the receivables that
  settle themselves when the patient pays (`Clinic/PayoutControllerTest.php`,
  `Clinic/ReceivableControllerTest.php`), the patient form's backfill guards —
  rekeying or retyping an answered field needs confirming, and a type change
  the answers cannot survive is refused outright
  (`Clinic/PatientFormFieldControllerTest.php`) — and the import drivers
  (`Clinic/PatientFormExtractionTest.php`).
- `tests/Unit/RevenueSplitTest.php` is a unit test for the money: the two sides
  always add back up to the net, whatever the share and whoever the discount
  comes out of. `tests/Feature/PatientIntakeTest.php` covers the public intake
  flow end to end: the link works once, prefills an existing patient, validates
  against the clinic's own fields, and is attributed to nobody.
- `tests/Feature/Admin/AdminPagesTest.php` covers the web routing: where
  `/admin` sends each kind of user, page permissions, cross-clinic 404s and the
  shared `clinicContext` prop.
- `tests/Feature/Auth/PasswordResetTest.php` also checks that the reset email
  is pushed to the queue.
- `tests/Feature/DashboardTest.php` and `tests/Feature/ExampleTest.php` cover the
  `/` versus `/admin` split: the public home renders `public/Home`, guests at
  `/admin` are redirected to `/admin/login`, and login lands on `/admin`.
- Run tests in Docker with `docker compose exec app php artisan test --compact`.
  For why tests never touch the dev MySQL database, see
  [docker.md](docker.md#why-database-settings-live-in-env-not-composeyaml).
- `composer ci:check` runs everything CI runs: lint, format, types, Pint,
  PHPStan and tests.
