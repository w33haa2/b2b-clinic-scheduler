/**
 * Human-readable labels for clinic permissions (API route names such as
 * `clinic.sessions.members.payment.update`), grouped by what they act on.
 */

const groups: Array<[prefix: string, label: string]> = [
    ['sessions.members.line-items', 'Billed items'],
    ['sessions.members.payment', 'Payments'],
    ['sessions.members', 'Session patients'],
    ['sessions.status', 'Sessions'],
    ['sessions', 'Sessions'],
    ['patients.uninvoiced-items', 'Invoices'],
    ['patients.progress-notes', 'Progress notes'],
    ['progress-notes', 'Progress notes'],
    ['patients.history', 'Patients'],
    ['patients', 'Patients'],
    ['patient-form-invitations', 'Patient intake links'],
    ['patient-form', 'Patient form builder'],
    ['line-items', 'Services & pricing'],
    ['discounts', 'Discounts'],
    ['transactions', 'Transactions'],
    ['invoices', 'Invoices'],
    ['attendance', 'Attendance'],
    ['payouts.preview', 'Payouts'],
    ['payouts.status', 'Payouts'],
    ['payouts', 'Payouts'],
    ['members', 'Staff'],
    ['roles', 'Roles'],
    ['permissions', 'Roles'],
];

const specialLabels: Record<string, string> = {
    'sessions.status.update': 'Change session status',
    'sessions.members.payment.update': 'Record payments and refunds',
    'patients.uninvoiced-items.index': 'See items not yet invoiced',
    'permissions.index': 'See available permissions',
    'patient-form.show': 'View the form',
    'patient-form.reorder': 'Rearrange the form',
    'patient-form.extract.store': 'Import a form from a file',
    'patient-form.fields.bulk': 'Add imported fields',
    'patient-form.fields.restore': 'Restore a removed field',
    'patient-form.fields.usage': 'See how often a field is answered',
    'patients.history.index': 'View a patient history',
    'patient-form-invitations.resend': 'Send a new link',
    'attendance.store': 'Record attendance',
    'attendance.update': 'Edit attendance',
    'attendance.destroy': 'Remove attendance',
    'discounts.store': 'Create (personal only; clinic-wide needs admin)',
    'payouts.store': 'Generate payouts',
    'payouts.preview.store': 'Preview a payout',
    'payouts.status.update': 'Mark paid or cancel',
    'payouts.destroy': 'Delete a draft payout',
};

const actionLabels: Record<string, string> = {
    index: 'View list',
    show: 'View details',
    store: 'Create',
    update: 'Edit',
    destroy: 'Delete',
};

export type DescribedPermission = {
    name: string;
    group: string;
    label: string;
};

export function describePermission(name: string): DescribedPermission {
    const path = name.replace(/^clinic\./, '');
    const action = path.split('.').pop() ?? path;
    const group =
        groups.find(
            ([prefix]) => path === prefix || path.startsWith(`${prefix}.`),
        )?.[1] ?? 'Other';

    return {
        name,
        group,
        label: specialLabels[path] ?? actionLabels[action] ?? action,
    };
}

/** Permissions grouped for display, groups in the order above. */
export function groupPermissions<T extends { name: string }>(
    permissions: T[],
): Array<{ group: string; items: Array<T & DescribedPermission> }> {
    const order = [...new Set(groups.map(([, label]) => label)), 'Other'];
    const byGroup = new Map<string, Array<T & DescribedPermission>>();

    for (const permission of permissions) {
        const described = {
            ...permission,
            ...describePermission(permission.name),
        };
        byGroup.set(described.group, [
            ...(byGroup.get(described.group) ?? []),
            described,
        ]);
    }

    const actionOrder = ['index', 'show', 'store', 'update', 'destroy'];
    const rank = (name: string) => {
        const position = actionOrder.indexOf(name.split('.').pop() ?? '');

        return position === -1 ? actionOrder.length : position;
    };

    return order
        .filter((group) => byGroup.has(group))
        .map((group) => ({
            group,
            items: byGroup
                .get(group)!
                .sort((a, b) => rank(a.name) - rank(b.name)),
        }));
}
