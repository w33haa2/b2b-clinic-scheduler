<?php

use App\Http\Controllers\Api\AuthTokenController;
use App\Http\Controllers\Api\Clinic\ActivityLogController;
use App\Http\Controllers\Api\Clinic\AttendanceController;
use App\Http\Controllers\Api\Clinic\CalendarFilterController;
use App\Http\Controllers\Api\Clinic\ClinicController;
use App\Http\Controllers\Api\Clinic\ClinicSessionController;
use App\Http\Controllers\Api\Clinic\DailySessionController;
use App\Http\Controllers\Api\Clinic\DailySessionPatientController;
use App\Http\Controllers\Api\Clinic\DiscountController;
use App\Http\Controllers\Api\Clinic\InvitationController;
use App\Http\Controllers\Api\Clinic\InvoiceController;
use App\Http\Controllers\Api\Clinic\InvoicePdfController;
use App\Http\Controllers\Api\Clinic\LineItemController;
use App\Http\Controllers\Api\Clinic\MemberController;
use App\Http\Controllers\Api\Clinic\PatientController;
use App\Http\Controllers\Api\Clinic\PatientFormController;
use App\Http\Controllers\Api\Clinic\PatientFormExtractionController;
use App\Http\Controllers\Api\Clinic\PatientFormFieldController;
use App\Http\Controllers\Api\Clinic\PatientFormInvitationController;
use App\Http\Controllers\Api\Clinic\PatientFormPdfController;
use App\Http\Controllers\Api\Clinic\PatientHistoryController;
use App\Http\Controllers\Api\Clinic\PatientProgressNoteController;
use App\Http\Controllers\Api\Clinic\PaymentController;
use App\Http\Controllers\Api\Clinic\PaymentStatusController;
use App\Http\Controllers\Api\Clinic\PayoutController;
use App\Http\Controllers\Api\Clinic\PayoutPreviewController;
use App\Http\Controllers\Api\Clinic\PayoutSettingController;
use App\Http\Controllers\Api\Clinic\PayoutStatusController;
use App\Http\Controllers\Api\Clinic\PermissionController;
use App\Http\Controllers\Api\Clinic\PrepaymentController;
use App\Http\Controllers\Api\Clinic\ReceivableController;
use App\Http\Controllers\Api\Clinic\RoleController;
use App\Http\Controllers\Api\Clinic\SessionLineItemController;
use App\Http\Controllers\Api\Clinic\SessionMemberController;
use App\Http\Controllers\Api\Clinic\SessionMemberSlotController;
use App\Http\Controllers\Api\Clinic\SessionStatusController;
use App\Http\Controllers\Api\Clinic\TransactionController;
use App\Http\Controllers\Api\Clinic\UninvoicedItemController;
use App\Http\Controllers\Api\Internal\ActivityLogController as InternalActivityLogController;
use App\Http\Controllers\Api\Internal\ClinicController as InternalClinicController;
use App\Http\Controllers\Api\Internal\ClinicInvitationController as InternalClinicInvitationController;
use App\Http\Controllers\Api\Internal\ClinicRoleController as InternalClinicRoleController;
use App\Http\Controllers\Api\Internal\ClinicUserController as InternalClinicUserController;
use App\Http\Controllers\Api\Internal\PermissionController as InternalPermissionController;
use App\Http\Controllers\Api\Internal\SuperAdminController;
use App\Http\Controllers\Api\MeController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| API
|--------------------------------------------------------------------------
|
| Every route here is prefixed with "/api". Authentication is Sanctum: a
| bearer token from POST /api/tokens, or the browser session cookie when
| called from the Inertia frontend. See docs/api.md.
|
*/

Route::post('tokens', [AuthTokenController::class, 'store'])
    ->middleware('throttle:login')
    ->name('tokens.store');

Route::middleware('auth:sanctum')->group(function () {
    Route::delete('tokens/current', [AuthTokenController::class, 'destroy'])->name('tokens.destroy');
    Route::get('me', MeController::class)->name('me');
    Route::get('clinics', [ClinicController::class, 'index'])->name('clinics.index');

    /*
    |----------------------------------------------------------------------
    | Clinic API — /api/clinics/{clinic}/...
    |----------------------------------------------------------------------
    |
    | "clinic.member" requires the user to belong to {clinic} (404 if not).
    | Routes under "clinic.permission" become grantable permissions named
    | after the route (run `php artisan clinic:sync-permissions`). Routes
    | under "clinic.admin" are reserved for the Clinic Admin role.
    |
    */
    Route::prefix('clinics/{clinic}')
        ->name('clinic.')
        ->middleware('clinic.member')
        ->scopeBindings()
        ->group(function () {
            Route::get('/', [ClinicController::class, 'show'])->name('show');

            // Personal: each member only ever sees their own saved filters.
            Route::apiResource('calendar-filters', CalendarFilterController::class)
                ->except(['show'])
                ->parameters(['calendar-filters' => 'calendarFilter']);

            Route::middleware('clinic.admin')->group(function () {
                Route::put('/', [ClinicController::class, 'update'])->name('update');
                // Payout configuration and the money advanced against unpaid
                // patients are the clinic's books, not a worker's business.
                Route::get('payout-settings', [PayoutSettingController::class, 'show'])->name('payout-settings.show');
                Route::put('payout-settings', [PayoutSettingController::class, 'update'])->name('payout-settings.update');
                Route::get('receivables', [ReceivableController::class, 'index'])->name('receivables.index');
                Route::apiResource('invitations', InvitationController::class)
                    ->only(['index', 'store', 'destroy'])
                    ->parameters(['invitations' => 'clinicInvitation']);
                Route::post('invitations/{clinicInvitation}/resend', [InvitationController::class, 'resend'])
                    ->name('invitations.resend');
                Route::apiResource('members', MemberController::class)
                    ->only(['store', 'update', 'destroy'])
                    ->parameters(['members' => 'clinicUser']);
                Route::apiResource('roles', RoleController::class)
                    ->only(['store', 'update', 'destroy'])
                    ->parameters(['roles' => 'clinicRole']);
            });

            // Uses the invoice "view details" permission instead of being a permission of its own.
            Route::get('invoices/{invoice}/pdf', [InvoicePdfController::class, 'show'])
                ->middleware('clinic.permission:clinic.invoices.show')
                ->name('invoices.pdf');

            // Printable forms. Both ride on the permission of the thing they
            // print rather than being permissions of their own.
            Route::get('patient-form/pdf', [PatientFormPdfController::class, 'blank'])
                ->middleware('clinic.permission:clinic.patient-form.show')
                ->name('patient-form.pdf');
            Route::get('patients/{patient}/pdf', [PatientFormPdfController::class, 'filled'])
                ->middleware('clinic.permission:clinic.patients.show')
                ->name('patients.pdf');

            Route::middleware('clinic.permission')->group(function () {
                /*
                 * The patient form. Everything a patient record looks like is
                 * defined here, so building the form is its own permission,
                 * separate from using it.
                 */
                Route::get('patient-form', [PatientFormController::class, 'show'])->name('patient-form.show');
                Route::put('patient-form/reorder', [PatientFormController::class, 'reorder'])->name('patient-form.reorder');
                Route::post('patient-form/extract', [PatientFormExtractionController::class, 'store'])->name('patient-form.extract.store');
                Route::post('patient-form/fields/bulk', [PatientFormExtractionController::class, 'bulk'])->name('patient-form.fields.bulk');
                Route::post('patient-form/fields/{patientFormField}/restore', [PatientFormFieldController::class, 'restore'])
                    ->name('patient-form.fields.restore');
                Route::get('patient-form/fields/{patientFormField}/usage', [PatientFormFieldController::class, 'usage'])
                    ->name('patient-form.fields.usage');
                Route::apiResource('patient-form/fields', PatientFormFieldController::class)
                    ->parameters(['fields' => 'patientFormField'])
                    ->names('patient-form.fields');

                Route::apiResource('patient-form-invitations', PatientFormInvitationController::class)
                    ->only(['index', 'store', 'destroy'])
                    ->parameters(['patient-form-invitations' => 'patientFormInvitation']);
                Route::post('patient-form-invitations/{patientFormInvitation}/resend', [PatientFormInvitationController::class, 'resend'])
                    ->name('patient-form-invitations.resend');

                Route::get('progress-notes', [PatientProgressNoteController::class, 'clinicIndex'])->name('progress-notes.index');

                Route::apiResource('patients', PatientController::class);
                Route::get('patients/{patient}/uninvoiced-items', UninvoicedItemController::class)
                    ->name('patients.uninvoiced-items.index');
                Route::get('patients/{patient}/history', [PatientHistoryController::class, 'show'])
                    ->name('patients.history.index');
                Route::apiResource('patients.progress-notes', PatientProgressNoteController::class)
                    ->only(['index', 'store', 'update', 'destroy'])
                    ->parameters(['progress-notes' => 'progressNote']);

                Route::apiResource('sessions', ClinicSessionController::class)
                    ->parameters(['sessions' => 'clinicSession']);
                Route::put('sessions/{clinicSession}/status', [SessionStatusController::class, 'update'])
                    ->name('sessions.status.update');
                Route::apiResource('sessions.members', SessionMemberController::class)
                    ->only(['index', 'store', 'destroy'])
                    ->parameters(['sessions' => 'clinicSession', 'members' => 'sessionMember']);
                Route::put('sessions/{clinicSession}/members/{sessionMember}/payment', [PaymentStatusController::class, 'update'])
                    ->name('sessions.members.payment.update');
                Route::put('sessions/{clinicSession}/members/{sessionMember}/slot', [SessionMemberSlotController::class, 'update'])
                    ->name('sessions.members.slot.update');
                Route::apiResource('sessions.members.line-items', SessionLineItemController::class)
                    ->except(['show'])
                    ->parameters(['sessions' => 'clinicSession', 'members' => 'sessionMember', 'line-items' => 'sessionLineItem']);

                Route::apiResource('daily-sessions', DailySessionController::class)
                    ->parameters(['daily-sessions' => 'dailySession']);
                Route::apiResource('daily-sessions.patients', DailySessionPatientController::class)
                    ->only(['store', 'update', 'destroy'])
                    ->parameters(['daily-sessions' => 'dailySession', 'patients' => 'dailySessionPatient']);

                Route::post('payments/prepay', [PrepaymentController::class, 'store'])->name('payments.prepay.store');
                Route::apiResource('payments', PaymentController::class)->only(['index', 'show']);

                Route::apiResource('attendance', AttendanceController::class)
                    ->parameters(['attendance' => 'attendance']);
                Route::apiResource('discounts', DiscountController::class)
                    ->parameters(['discounts' => 'discount']);

                Route::post('payouts/preview', [PayoutPreviewController::class, 'store'])->name('payouts.preview.store');
                Route::put('payouts/{payout}/status', [PayoutStatusController::class, 'update'])->name('payouts.status.update');
                Route::apiResource('payouts', PayoutController::class)->except(['update']);

                Route::apiResource('line-items', LineItemController::class)
                    ->parameters(['line-items' => 'lineItem']);
                Route::apiResource('transactions', TransactionController::class)->only(['index', 'show']);
                Route::apiResource('invoices', InvoiceController::class);

                Route::apiResource('members', MemberController::class)
                    ->only(['index', 'show'])
                    ->parameters(['members' => 'clinicUser']);
                Route::apiResource('roles', RoleController::class)
                    ->only(['index', 'show'])
                    ->parameters(['roles' => 'clinicRole']);
                Route::get('permissions', [PermissionController::class, 'index'])->name('permissions.index');
                Route::get('activity', [ActivityLogController::class, 'index'])->name('activity.index');
            });
        });

    /*
    |----------------------------------------------------------------------
    | Internal (super admin) API — /api/internal/...
    |----------------------------------------------------------------------
    |
    | Only users with is_super_admin. No clinic role can ever reach these.
    |
    */
    Route::prefix('internal')
        ->name('internal.')
        ->middleware('super-admin')
        ->scopeBindings()
        ->group(function () {
            Route::apiResource('super-admins', SuperAdminController::class)
                ->parameters(['super-admins' => 'user']);
            Route::apiResource('clinics', InternalClinicController::class);
            Route::apiResource('clinics.users', InternalClinicUserController::class)
                ->parameters(['users' => 'clinicUser']);
            Route::apiResource('clinics.invitations', InternalClinicInvitationController::class)
                ->only(['index', 'store', 'destroy'])
                ->parameters(['invitations' => 'clinicInvitation']);
            Route::post('clinics/{clinic}/invitations/{clinicInvitation}/resend', [InternalClinicInvitationController::class, 'resend'])
                ->name('clinics.invitations.resend');
            Route::apiResource('clinics.roles', InternalClinicRoleController::class)
                ->parameters(['roles' => 'clinicRole']);
            Route::get('permissions', [InternalPermissionController::class, 'index'])->name('permissions.index');
            Route::get('activity', [InternalActivityLogController::class, 'index'])->name('activity.index');
        });
});
