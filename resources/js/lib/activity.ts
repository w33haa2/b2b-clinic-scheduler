import type { ActivityChange } from '@/types';

/**
 * Display helpers for activity log changes ("field: old → new").
 */
const fieldLabels: Record<string, string> = {
    status_id: 'Status',
    payment_status_id: 'Payment',
    clinic_user_id: 'Held by (staff #)',
    starts_at: 'Starts',
    ends_at: 'Ends',
    line_item_id: 'Service #',
    unit_price: 'Unit price',
    item_code: 'Code',
    issued_at: 'Issue date',
    birth_date: 'Birth date',
    is_super_admin: 'Super admin',
};

export function fieldLabel(field: string): string {
    if (fieldLabels[field]) {
        return fieldLabels[field];
    }

    const words = field.replace(/_id$/, '').replaceAll('_', ' ');

    return words.charAt(0).toUpperCase() + words.slice(1);
}

/** Personal fields are logged as changed without their values. */
export function isRedacted(change: ActivityChange): boolean {
    return change.old === null && change.new === null;
}

export function formatValue(value: unknown): string {
    if (value === null || value === undefined || value === '') {
        return '—';
    }

    if (Array.isArray(value)) {
        return value.length ? value.join(', ') : 'none';
    }

    if (typeof value === 'boolean') {
        return value ? 'Yes' : 'No';
    }

    // Stored timestamps are UTC "YYYY-MM-DD HH:MM:SS".
    if (
        typeof value === 'string' &&
        /^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/.test(value)
    ) {
        return new Date(`${value.replace(' ', 'T')}Z`).toLocaleString(
            undefined,
            {
                dateStyle: 'medium',
                timeStyle: 'short',
            },
        );
    }

    return typeof value === 'object'
        ? JSON.stringify(value)
        : String(value as string | number | bigint);
}

/**
 * Pivot changes are logged as what was removed (old) and added (new), not as
 * a before/after pair.
 */
export function isDelta(field: string): boolean {
    return field === 'permissions' || field === 'items';
}
