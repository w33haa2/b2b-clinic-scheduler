<?php

use App\Http\Controllers\Admin\ClinicHomeController;
use App\Http\Controllers\Admin\DashboardController;
use App\Http\Controllers\Admin\InternalClinicPageController;
use App\Http\Controllers\Admin\InvitationController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Admin Area
|--------------------------------------------------------------------------
|
| Every route in this file is registered under the "/admin" prefix by
| routes/web.php. Fortify's authentication routes share the same prefix
| through the "prefix" option in config/fortify.php.
|
| These routes only render Inertia pages. The pages read and write their
| data through the JSON API in routes/api.php, which enforces the same
| clinic permissions per endpoint.
|
*/

/*
 * Clinic invitations, opened from the email link. No sign-in needed: the
 * token is the proof. Existing users join on opening it; first-time users
 * choose a password.
 */
Route::middleware('throttle:invitations')->group(function () {
    Route::get('invitations/{token}', [InvitationController::class, 'show'])->name('invitations.show');
    Route::post('invitations/{token}', [InvitationController::class, 'accept'])->name('invitations.accept');
});

Route::middleware(['auth', 'verified'])->group(function () {
    Route::get('/', DashboardController::class)->name('dashboard');

    /*
     * Clinic workspace. Each page requires the permission of the API list
     * endpoint it shows (passed explicitly, so these web routes are not
     * grantable permissions themselves).
     */
    Route::prefix('clinics/{clinic}')
        ->name('admin.clinics.')
        ->middleware('clinic.member')
        ->group(function () {
            Route::get('/', ClinicHomeController::class)->name('home');

            foreach (ClinicHomeController::PAGES as $routeName => $permission) {
                $page = str($routeName)->afterLast('.');

                Route::inertia((string) $page, 'admin/clinic/'.$page->studly())
                    ->middleware("clinic.permission:{$permission}")
                    ->name((string) $page);
            }

            /*
             * The clinic's own books and configuration. Clinic Admin only,
             * and never a grantable permission.
             */
            foreach (ClinicHomeController::ADMIN_PAGES as $routeName) {
                $page = str($routeName)->afterLast('.');

                Route::inertia((string) $page, 'admin/clinic/'.$page->studly())
                    ->middleware('clinic.admin')
                    ->name((string) $page);
            }
        });

    /*
     * Internal tool for super admins.
     */
    Route::prefix('internal')
        ->name('admin.internal.')
        ->middleware('super-admin')
        ->group(function () {
            Route::redirect('/', '/admin/internal/clinics')->name('home');
            Route::inertia('clinics', 'admin/internal/Clinics')->name('clinics.index');
            Route::get('clinics/{clinic}', [InternalClinicPageController::class, 'show'])->name('clinics.show');
            Route::inertia('super-admins', 'admin/internal/SuperAdmins')->name('super-admins');
            Route::inertia('activity', 'admin/internal/Activity')->name('activity');
        });
});

require __DIR__.'/settings.php';
