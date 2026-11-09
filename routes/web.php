<?php

use App\Http\Controllers\PatientIntakeController;
use App\Http\Controllers\PublicSiteController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Public Site
|--------------------------------------------------------------------------
|
| Routes for the public-facing site served from "/". Admin routes are
| registered under the "/admin" prefix from routes/admin.php.
|
*/

Route::get('/', [PublicSiteController::class, 'home'])->name('home');
Route::get('robots.txt', [PublicSiteController::class, 'robots'])->name('robots');
Route::get('sitemap.xml', [PublicSiteController::class, 'sitemap'])->name('sitemap');

/*
 * The patient intake link. No sign-in: the token in the URL is the proof,
 * it works once, and only its hash is stored. This sits outside /admin
 * because the person filling it in is a patient, not a member of staff.
 */
Route::middleware('throttle:invitations')->group(function () {
    Route::get('patient-form/{token}', [PatientIntakeController::class, 'show'])->name('patient-form.show');
    Route::post('patient-form/{token}', [PatientIntakeController::class, 'submit'])->name('patient-form.submit');
});

Route::prefix('admin')->group(__DIR__.'/admin.php');
