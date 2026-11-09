<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Clinic;
use App\Support\ClinicContext;
use Illuminate\Http\RedirectResponse;

class ClinicHomeController extends Controller
{
    /**
     * Clinic pages in sidebar order, with the permission each one needs.
     *
     * @var array<string, string>
     */
    public const PAGES = [
        'admin.clinics.schedule' => 'clinic.sessions.index',
        'admin.clinics.daily-sessions' => 'clinic.daily-sessions.index',
        'admin.clinics.patients' => 'clinic.patients.index',
        'admin.clinics.progress-notes' => 'clinic.progress-notes.index',
        'admin.clinics.patient-form' => 'clinic.patient-form.show',
        'admin.clinics.services' => 'clinic.line-items.index',
        'admin.clinics.discounts' => 'clinic.discounts.index',
        'admin.clinics.payments' => 'clinic.payments.index',
        'admin.clinics.invoices' => 'clinic.invoices.index',
        'admin.clinics.transactions' => 'clinic.transactions.index',
        'admin.clinics.attendance' => 'clinic.attendance.index',
        'admin.clinics.payouts' => 'clinic.payouts.index',
        'admin.clinics.members' => 'clinic.members.index',
        'admin.clinics.roles' => 'clinic.roles.index',
        'admin.clinics.activity' => 'clinic.activity.index',
    ];

    /**
     * Clinic pages only a Clinic Admin may open, in sidebar order. They show
     * the clinic's own books and configuration, so they are not grantable.
     *
     * @var list<string>
     */
    public const ADMIN_PAGES = [
        'admin.clinics.receivables',
        'admin.clinics.payout-settings',
    ];

    /**
     * Open the first clinic page the member is allowed to see.
     */
    public function __invoke(Clinic $clinic, ClinicContext $context): RedirectResponse
    {
        foreach (self::PAGES as $routeName => $permission) {
            if ($context->member()->hasPermission($permission)) {
                return to_route($routeName, $clinic);
            }
        }

        if ($context->member()->isAdmin()) {
            return to_route(self::ADMIN_PAGES[0], $clinic);
        }

        abort(403, __('Your roles do not give you access to any page in this clinic.'));
    }
}
