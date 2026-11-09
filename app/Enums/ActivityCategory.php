<?php

namespace App\Enums;

/**
 * Groups activity log actions for filtering. Each category matches actions
 * by their prefix (the part before the first dot).
 */
enum ActivityCategory: string
{
    case Patients = 'patients';
    case Schedule = 'schedule';
    case Billing = 'billing';
    case Invoices = 'invoices';
    case Services = 'services';
    case Payroll = 'payroll';
    case Staff = 'staff';
    case Clinic = 'clinic';
    case Account = 'account';

    public function label(): string
    {
        return match ($this) {
            self::Patients => 'Patients',
            self::Schedule => 'Schedule',
            self::Billing => 'Billing & payments',
            self::Invoices => 'Invoices',
            self::Services => 'Services & pricing',
            self::Payroll => 'Payouts & attendance',
            self::Staff => 'Staff & roles',
            self::Clinic => 'Clinic settings',
            self::Account => 'Sign-ins & accounts',
        };
    }

    /**
     * Action prefixes in this category.
     *
     * @return list<string>
     */
    public function prefixes(): array
    {
        return match ($this) {
            self::Patients => ['patient', 'patient_form', 'patient_form_field', 'patient_form_invitation', 'progress_note'],
            self::Schedule => ['session', 'session_patient', 'daily_session', 'daily_session_patient', 'calendar_filter'],
            self::Billing => ['billed_item', 'payment'],
            self::Invoices => ['invoice'],
            self::Services => ['service'],
            self::Payroll => ['payout', 'attendance', 'discount', 'payout_setting'],
            self::Staff => ['member', 'role', 'invitation'],
            self::Clinic => ['clinic'],
            self::Account => ['auth', 'api_token', 'user'],
        };
    }

    public static function forAction(string $action): ?self
    {
        $prefix = strstr($action, '.', true) ?: $action;

        foreach (self::cases() as $category) {
            if (in_array($prefix, $category->prefixes(), true)) {
                return $category;
            }
        }

        return null;
    }
}
