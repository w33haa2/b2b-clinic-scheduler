<?php

namespace App\Enums;

/**
 * The built-in roles every clinic gets. Custom roles have no key.
 */
enum ClinicRoleKey: string
{
    case Admin = 'admin';
    case Worker = 'worker';

    /**
     * The display name the role is created with.
     */
    public function label(): string
    {
        return match ($this) {
            self::Admin => 'Clinic Admin',
            self::Worker => 'Clinic Worker',
        };
    }

    /**
     * The permissions the role starts with. The admin role implicitly has
     * every permission, so it never stores any.
     *
     * @return list<string>
     */
    public function defaultPermissions(): array
    {
        return match ($this) {
            self::Admin => [],
            self::Worker => [
                'clinic.patients.index',
                'clinic.patients.show',
                'clinic.patients.store',
                'clinic.patients.update',
                'clinic.sessions.index',
                'clinic.sessions.show',
                'clinic.sessions.store',
                'clinic.sessions.update',
                'clinic.sessions.status.update',
                'clinic.sessions.members.index',
                'clinic.sessions.members.store',
                'clinic.sessions.members.destroy',
                'clinic.sessions.members.payment.update',
                'clinic.sessions.members.slot.update',
                'clinic.sessions.members.line-items.index',
                'clinic.sessions.members.line-items.store',
                'clinic.sessions.members.line-items.update',
                'clinic.sessions.members.line-items.destroy',
                'clinic.daily-sessions.index',
                'clinic.daily-sessions.show',
                'clinic.daily-sessions.store',
                'clinic.daily-sessions.update',
                'clinic.daily-sessions.patients.store',
                'clinic.daily-sessions.patients.update',
                'clinic.daily-sessions.patients.destroy',
                'clinic.payments.index',
                'clinic.payments.show',
                'clinic.payments.prepay.store',
                'clinic.line-items.index',
                'clinic.line-items.show',
                'clinic.members.index',
                'clinic.members.show',
                // The API narrows each of these to the member's own records:
                // their own attendance, their own payouts, and personal
                // discounts only. Clinic-wide discounts stay with the admin.
                'clinic.attendance.index',
                'clinic.attendance.store',
                'clinic.attendance.update',
                'clinic.discounts.index',
                'clinic.discounts.show',
                'clinic.discounts.store',
                'clinic.discounts.update',
                'clinic.discounts.destroy',
                'clinic.payouts.index',
                'clinic.payouts.show',
                // The patient module. Building the form is deliberately left
                // out: it changes what every patient record looks like, so it
                // stays with Clinic Admins until a role is granted it.
                'clinic.patient-form.show',
                'clinic.patients.history.index',
                'clinic.patients.progress-notes.index',
                'clinic.patients.progress-notes.store',
                'clinic.patients.progress-notes.update',
                'clinic.patients.progress-notes.destroy',
                'clinic.progress-notes.index',
                'clinic.patient-form-invitations.index',
                'clinic.patient-form-invitations.store',
                'clinic.patient-form-invitations.resend',
                'clinic.patient-form-invitations.destroy',
            ],
        };
    }
}
