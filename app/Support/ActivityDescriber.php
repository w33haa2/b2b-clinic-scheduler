<?php

namespace App\Support;

use App\Enums\AttendanceStatus;
use App\Enums\PaymentStatus;
use App\Enums\PayoutStatus;
use App\Enums\SessionStatus;
use App\Models\ActivityLog;

/**
 * Turns an activity log row into a sentence for people, e.g.
 * "Changed status of Group physio on Tue 4 Nov, 09:00 from Scheduled to In progress".
 */
class ActivityDescriber
{
    /**
     * @var array<string, string>
     */
    private const NOUNS = [
        'patient' => 'patient',
        'session' => 'session',
        'session_patient' => 'patient in a session',
        'daily_session' => 'daily session',
        'calendar_filter' => 'saved schedule filter',
        'billed_item' => 'billed item',
        'service' => 'service',
        'invoice' => 'invoice',
        'member' => 'staff member',
        'role' => 'role',
        'clinic' => 'clinic',
        'user' => 'user account',
        'attendance' => 'attendance',
        'discount' => 'discount',
        'patient_form' => 'patient form',
        'patient_form_field' => 'patient form field',
        'patient_form_invitation' => 'patient intake link',
        'progress_note' => 'progress note',
        'payout' => 'payout',
        'payout_setting' => 'payout settings',
    ];

    /**
     * @var array<string, string>
     */
    private const SESSION_STATUSES = [
        'PENDING' => 'Scheduled',
        'ONGOING' => 'In progress',
        'DONE' => 'Done',
        'CANCELLED' => 'Cancelled',
    ];

    public function describe(ActivityLog $log): string
    {
        $label = $log->subject_label ?? '';
        $context = $log->context ?? [];
        $changes = $log->changes ?? [];

        return match ($log->action) {
            'session.status_changed' => sprintf(
                'Changed status of session %s from %s to %s',
                $label,
                $this->sessionStatus($changes['status_id']['old'] ?? null),
                $this->sessionStatus($changes['status_id']['new'] ?? null),
            ),
            'session_patient.added' => "Added {$label} to session ".($context['session'] ?? ''),
            'session_patient.removed' => "Removed {$label} from session ".($context['session'] ?? ''),
            'session_patient.plotted' => "Plotted {$label} into a time slot of session ".($context['session'] ?? ''),
            'session_patient.unplotted' => "Cleared the time slot of {$label} in session ".($context['session'] ?? ''),
            'daily_session_patient.enrolled' => "Enrolled {$label} in daily session ".($context['daily_session'] ?? ''),
            'daily_session_patient.removed' => "Removed {$label} from daily session ".($context['daily_session'] ?? ''),
            'daily_session_patient.plan_changed' => "Changed the regular plan of {$label} in daily session ".($context['daily_session'] ?? ''),
            'daily_session.days_scheduled' => sprintf('Scheduled %d more %s of daily session %s', $context['days'] ?? 0, ($context['days'] ?? 0) === 1 ? 'day' : 'days', $label),
            'payment.recorded' => (empty($context['prepaid']) ? 'Recorded payment' : 'Recorded prepayment')." from {$label}".$this->forSession($context),
            'payment.refunded' => "Refunded {$label}".$this->forSession($context),
            'payment.updated' => "Changed payment status of {$label}".$this->forSession($context),
            'billed_item.created' => "Billed {$label} to ".($context['patient'] ?? 'a patient'),
            'billed_item.updated' => "Changed billed item {$label} for ".($context['patient'] ?? 'a patient'),
            'billed_item.deleted' => "Removed billed item {$label} from ".($context['patient'] ?? 'a patient'),
            'member.added' => "Added {$label} to the staff",
            'member.removed' => "Removed {$label} from the staff",
            'member.roles_changed' => "Changed the roles of {$label}",
            'invitation.sent' => "Invited {$label} to join the clinic",
            'invitation.resent' => "Sent the invitation to {$label} again",
            'invitation.revoked' => "Cancelled the invitation for {$label}",
            'invitation.accepted' => "{$label} accepted the invitation and joined the clinic",
            'role.permissions_changed' => "Changed the permissions of role {$label}",
            'invoice.items_changed' => "Changed the items on invoice {$label}",
            'payout.generated' => sprintf(
                'Generated payout %s for %s (%s), %s over %d %s',
                $label,
                $context['member'] ?? 'a staff member',
                $context['period'] ?? '',
                $context['amount'] ?? '',
                $context['items'] ?? 0,
                ($context['items'] ?? 0) === 1 ? 'item' : 'items',
            ),
            'payout.paid' => "Paid payout {$label} to ".($context['member'] ?? 'a staff member'),
            'payout.cancelled' => "Cancelled payout {$label} and released its items",
            'payout.deleted' => "Deleted draft payout {$label}",
            'attendance.created' => "Recorded attendance for {$label}",
            'attendance.updated' => "Changed attendance for {$label}",
            'attendance.deleted' => "Removed attendance for {$label}",
            'discount.created' => 'Created '.($context['scope'] ?? '')." discount {$label}",
            'discount.archived' => "Archived discount {$label}",
            'payout_setting.created', 'payout_setting.updated' => 'Changed the clinic payout settings',
            'patient_form_field.created' => "Added \"{$label}\" to the patient form",
            'patient_form_field.updated' => "Changed the patient form field \"{$label}\"",
            'patient_form_field.moved' => "Moved \"{$label}\" on the patient form",
            'patient_form_field.removed' => "Removed \"{$label}\" from the patient form",
            'patient_form_field.restored' => "Put \"{$label}\" back on the patient form",
            'patient_form.reordered' => sprintf('Rearranged the patient form (%d fields)', $context['fields'] ?? 0),
            'patient_form_invitation.sent' => "Sent a patient form link to {$label}",
            'patient_form_invitation.revoked' => "Cancelled the patient form link for {$label}",
            'patient_form_invitation.submitted' => "{$label} filled in the patient form",
            'progress_note.created' => 'Wrote a progress note for '.($context['patient'] ?? 'a patient'),
            'progress_note.updated' => 'Changed a progress note for '.($context['patient'] ?? 'a patient'),
            'progress_note.archived', 'progress_note.deleted' => 'Removed a progress note for '.($context['patient'] ?? 'a patient'),
            'patient.downloaded' => "Downloaded the record of {$label}",
            'auth.login' => 'Signed in',
            'auth.logout' => 'Signed out',
            'auth.login_failed' => "Failed sign-in attempt for {$label}",
            'auth.password_reset' => 'Reset their password',
            'api_token.created' => "Created API token “{$label}”",
            'api_token.revoked' => "Revoked API token “{$label}”",
            default => $this->generic($log->action, $label),
        };
    }

    /**
     * Changes with lookup IDs turned into readable values.
     *
     * @return array<string, array{old: mixed, new: mixed}>
     */
    public function readableChanges(ActivityLog $log): array
    {
        $changes = $log->changes ?? [];

        foreach ($changes as $field => $change) {
            $changes[$field] = match ($field) {
                'status_id' => ['old' => $this->sessionStatus($change['old']), 'new' => $this->sessionStatus($change['new'])],
                'payment_status_id' => ['old' => $this->paymentStatus($change['old']), 'new' => $this->paymentStatus($change['new'])],
                'payout_status_id' => ['old' => $this->payoutStatus($change['old']), 'new' => $this->payoutStatus($change['new'])],
                'attendance_status_id' => ['old' => $this->attendanceStatus($change['old']), 'new' => $this->attendanceStatus($change['new'])],
                default => $change,
            };
        }

        return $changes;
    }

    private function generic(string $action, string $label): string
    {
        [$entity, $event] = array_pad(explode('.', $action, 2), 2, '');
        $noun = self::NOUNS[$entity] ?? str_replace('_', ' ', $entity);
        $verb = match ($event) {
            'created' => 'Created',
            'updated' => 'Updated',
            'archived' => 'Archived',
            'deleted' => 'Deleted',
            'restored' => 'Restored',
            default => ucfirst(str_replace('_', ' ', $event)),
        };

        return trim("{$verb} {$noun} {$label}");
    }

    /**
     * @param  array<string, mixed>  $context
     */
    private function forSession(array $context): string
    {
        return isset($context['session']) ? " for session {$context['session']}" : '';
    }

    private function sessionStatus(mixed $id): string
    {
        $status = is_numeric($id) ? SessionStatus::tryFrom((int) $id) : null;

        return $status ? self::SESSION_STATUSES[$status->code()] : '—';
    }

    private function paymentStatus(mixed $id): string
    {
        return match (is_numeric($id) ? PaymentStatus::tryFrom((int) $id) : null) {
            PaymentStatus::Pending => 'Unpaid',
            PaymentStatus::Done => 'Paid',
            PaymentStatus::Refunded => 'Refunded',
            null => '—',
        };
    }

    private function payoutStatus(mixed $id): string
    {
        return match (is_numeric($id) ? PayoutStatus::tryFrom((int) $id) : null) {
            PayoutStatus::Draft => 'Draft',
            PayoutStatus::Paid => 'Paid',
            PayoutStatus::Cancelled => 'Cancelled',
            null => '—',
        };
    }

    private function attendanceStatus(mixed $id): string
    {
        return match (is_numeric($id) ? AttendanceStatus::tryFrom((int) $id) : null) {
            AttendanceStatus::Present => 'Present',
            AttendanceStatus::Absent => 'Absent',
            AttendanceStatus::Late => 'Late',
            AttendanceStatus::Excused => 'Excused',
            null => '—',
        };
    }
}
