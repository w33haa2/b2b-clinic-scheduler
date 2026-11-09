import { patientName } from '@/lib/format';
import type {
    CalendarFilterCondition,
    CalendarFilterField,
    CalendarFilterOperator,
    CalendarFilterRules,
    ClinicSession,
    SessionMember,
} from '@/types';

/**
 * Schedule filters: the quick filters in the side panel and the filters
 * members save for themselves (stored by the API, applied here). A rule set
 * is a list of conditions matched with "all" or "any".
 */

/** A patient's payment as the filters see it. */
export type PaymentState = 'unpaid' | 'past_due' | 'paid' | 'refunded';

export const paymentStateLabels: Record<PaymentState, string> = {
    unpaid: 'Unpaid',
    past_due: 'Past due',
    paid: 'Paid',
    refunded: 'Refunded',
};

export const sessionTypeLabels: Record<'regular' | 'daily', string> = {
    regular: 'One-off session',
    daily: 'Daily session',
};

export const filterFields: Array<{
    value: CalendarFilterField;
    label: string;
    operators: CalendarFilterOperator[];
}> = [
    { value: 'staff', label: 'Staff member', operators: ['is', 'is_not'] },
    { value: 'status', label: 'Session status', operators: ['is', 'is_not'] },
    { value: 'type', label: 'Session type', operators: ['is', 'is_not'] },
    { value: 'patient', label: 'Patient', operators: ['is', 'is_not'] },
    { value: 'payment', label: 'Payment', operators: ['is', 'is_not'] },
    { value: 'name', label: 'Name', operators: ['contains', 'is_not'] },
];

export const operatorLabels: Record<
    CalendarFilterField,
    Partial<Record<CalendarFilterOperator, string>>
> = {
    staff: { is: 'is any of', is_not: 'is none of' },
    status: { is: 'is any of', is_not: 'is none of' },
    type: { is: 'is', is_not: 'is not' },
    patient: { is: 'includes any of', is_not: 'includes none of' },
    payment: { is: 'has any', is_not: 'has none' },
    name: { contains: 'contains', is_not: 'does not contain' },
};

export function paymentState(
    member: SessionMember,
    session: ClinicSession,
    now: Date,
): PaymentState | null {
    switch (member.payment_status) {
        case 'DONE':
            return 'paid';
        case 'REFUNDED':
            return 'refunded';
        case 'PENDING':
            if (session.status === 'CANCELLED') {
                return null;
            }

            return new Date(session.ends_at) < now ? 'past_due' : 'unpaid';
        default:
            return null;
    }
}

/** The values a session (or one patient's slot in it) has for a field. */
function valuesOf(
    field: CalendarFilterField,
    session: ClinicSession,
    members: SessionMember[],
    now: Date,
): string[] {
    switch (field) {
        case 'staff':
            return [String(session.clinic_user?.id ?? '')];
        case 'status':
            return [session.status];
        case 'type':
            return [session.daily_session ? 'daily' : 'regular'];
        case 'patient':
            return members.map((member) => String(member.patient?.id ?? ''));
        case 'payment':
            return members
                .map((member) => paymentState(member, session, now))
                .filter((state): state is PaymentState => state !== null);
        case 'name':
            return [
                session.name ?? '',
                session.daily_session?.name ?? '',
                ...members.map((member) => patientName(member.patient)),
            ];
    }
}

function matchesCondition(
    condition: CalendarFilterCondition,
    session: ClinicSession,
    members: SessionMember[],
    now: Date,
): boolean {
    const values = valuesOf(condition.field, session, members, now);

    if (condition.field === 'name') {
        const haystack = values.join(' ').toLowerCase();
        const found = condition.values.some((needle) =>
            haystack.includes(needle.toLowerCase()),
        );

        return condition.operator === 'is_not' ? !found : found;
    }

    const found = values.some((value) => condition.values.includes(value));

    return condition.operator === 'is_not' ? !found : found;
}

/**
 * Whether a session matches the rules. Pass `member` to test one patient's
 * slot of a daily session: patient and payment conditions then look at that
 * patient only.
 */
export function matchesRules(
    rules: CalendarFilterRules | null,
    session: ClinicSession,
    now: Date,
    member?: SessionMember,
): boolean {
    if (!rules || rules.conditions.length === 0) {
        return true;
    }

    const members = member ? [member] : (session.members ?? []);
    const results = rules.conditions.map((condition) =>
        matchesCondition(condition, session, members, now),
    );

    return rules.match === 'any'
        ? results.some(Boolean)
        : results.every(Boolean);
}

export type QuickFilters = {
    statuses: ClinicSession['status'][];
    type: 'all' | 'regular' | 'daily';
    payment: 'all' | PaymentState;
};

export const defaultQuickFilters = (): QuickFilters => ({
    statuses: [],
    type: 'all',
    payment: 'all',
});

/** The side panel's quick filters as a rule set ("all" must match). */
export function quickFilterRules(filters: QuickFilters): CalendarFilterRules {
    const conditions: CalendarFilterCondition[] = [];

    if (filters.statuses.length) {
        conditions.push({
            field: 'status',
            operator: 'is',
            values: filters.statuses,
        });
    }

    if (filters.type !== 'all') {
        conditions.push({
            field: 'type',
            operator: 'is',
            values: [filters.type],
        });
    }

    if (filters.payment !== 'all') {
        conditions.push({
            field: 'payment',
            operator: 'is',
            values: [filters.payment],
        });
    }

    return { match: 'all', conditions };
}
