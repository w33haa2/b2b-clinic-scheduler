import type {
    AttendanceStatus,
    DiscountTarget,
    DiscountType,
    PatientFormFieldType,
    PaymentStatus,
    Patient,
    PayoutStatus,
    ProgressRating,
    SessionStatus,
    TransactionType,
} from '@/types';

const moneyFormatter = new Intl.NumberFormat(undefined, {
    minimumFractionDigits: 2,
    maximumFractionDigits: 2,
});

/** "1500.00" → "1,500.00" in the viewer's locale. The API sends money as strings. */
export function formatMoney(amount: string | number): string {
    return moneyFormatter.format(Number(amount));
}

/** Sum API money strings without float drift (works in cents). */
export function sumMoney(amounts: Array<string | number>): string {
    const cents = amounts.reduce<number>(
        (total, amount) => total + Math.round(Number(amount) * 100),
        0,
    );

    return (cents / 100).toFixed(2);
}

export function formatDate(iso: string | null | undefined): string {
    if (!iso) {
        return '—';
    }

    return new Date(
        iso.length === 10 ? `${iso}T00:00:00` : iso,
    ).toLocaleDateString(undefined, {
        year: 'numeric',
        month: 'short',
        day: 'numeric',
    });
}

export function formatDateTime(iso: string): string {
    return new Date(iso).toLocaleString(undefined, {
        weekday: 'short',
        month: 'short',
        day: 'numeric',
        hour: 'numeric',
        minute: '2-digit',
    });
}

export function formatTime(iso: string): string {
    return new Date(iso).toLocaleTimeString(undefined, {
        hour: 'numeric',
        minute: '2-digit',
    });
}

export function patientName(
    patient: Pick<Patient, 'first_name' | 'last_name'> | undefined,
): string {
    return patient
        ? `${patient.first_name} ${patient.last_name}`
        : 'Unknown patient';
}

export function ageFrom(birthDate: string | null): number | null {
    if (!birthDate) {
        return null;
    }

    const born = new Date(`${birthDate}T00:00:00`);
    const today = new Date();
    let age = today.getFullYear() - born.getFullYear();

    if (
        today < new Date(today.getFullYear(), born.getMonth(), born.getDate())
    ) {
        age--;
    }

    return age;
}

/** Value for <input type="datetime-local"> from an ISO string, in the viewer's timezone. */
export function toDateTimeLocal(value: Date | string): string {
    const date = typeof value === 'string' ? new Date(value) : value;
    const pad = (n: number) => String(n).padStart(2, '0');

    return `${date.getFullYear()}-${pad(date.getMonth() + 1)}-${pad(date.getDate())}T${pad(date.getHours())}:${pad(date.getMinutes())}`;
}

/** ISO 8601 (with the viewer's offset baked in as UTC) from a datetime-local value. */
export function fromDateTimeLocal(value: string): string {
    return new Date(value).toISOString();
}

export const sessionStatusLabels: Record<SessionStatus, string> = {
    PENDING: 'Scheduled',
    ONGOING: 'In progress',
    DONE: 'Done',
    CANCELLED: 'Cancelled',
};

export const paymentStatusLabels: Record<PaymentStatus, string> = {
    PENDING: 'Unpaid',
    DONE: 'Paid',
    REFUNDED: 'Refunded',
};

export const transactionTypeLabels: Record<TransactionType, string> = {
    INBOUND: 'Payment',
    REVERSED: 'Refund',
};

export const attendanceStatusLabels: Record<AttendanceStatus, string> = {
    PRESENT: 'Present',
    ABSENT: 'Absent',
    LATE: 'Late',
    EXCUSED: 'Excused',
};

export const payoutStatusLabels: Record<PayoutStatus, string> = {
    DRAFT: 'Draft',
    PAID: 'Paid',
    CANCELLED: 'Cancelled',
};

/** Whose cut a discount comes out of. */
export const discountTargetLabels: Record<DiscountTarget, string> = {
    CLINIC: "Clinic's cut",
    WORKER: "Staff member's cut",
};

export const discountTypeLabels: Record<DiscountType, string> = {
    PERCENTAGE: 'Percentage',
    FIXED: 'Fixed amount',
};

/** "10.00" as "10%" or "₱1,000.00", depending on the discount type. */
export function formatDiscountValue(type: DiscountType, value: string): string {
    return type === 'PERCENTAGE' ? `${Number(value)}%` : formatMoney(value);
}

export const progressRatingLabels: Record<ProgressRating, string> = {
    BASELINE: 'Baseline',
    IMPROVED: 'Improved',
    UNCHANGED: 'No change',
    DECLINED: 'Declined',
    RESOLVED: 'Resolved',
};

export const patientFormFieldTypeLabels: Record<PatientFormFieldType, string> =
    {
        TEXT: 'Short text',
        TEXTAREA: 'Long text',
        NUMBER: 'Number',
        DATE: 'Date',
        SELECT: 'Dropdown',
        MULTISELECT: 'Multi-select',
        CHECKBOX: 'Checkbox',
        RADIO: 'Choice',
        EMAIL: 'Email',
        PHONE: 'Phone',
    };

/** Field types that need a list of choices. */
export function fieldTypeHasOptions(type: PatientFormFieldType): boolean {
    return type === 'SELECT' || type === 'MULTISELECT' || type === 'RADIO';
}

/** A plain "YYYY-MM-DD" as a readable date, without timezone shifting. */
export function formatDateOnly(date: string | null | undefined): string {
    if (!date) {
        return '—';
    }

    const [year, month, day] = date.split('-').map(Number);

    return formatDate(new Date(year, month - 1, day).toISOString());
}

/** 95 → "1h 35m"; null → "—". */
export function formatMinutes(minutes: number | null): string {
    if (minutes === null) {
        return '—';
    }

    const hours = Math.floor(minutes / 60);

    return hours ? `${hours}h ${minutes % 60}m` : `${minutes}m`;
}

/** ISO weekdays (1 = Monday … 7 = Sunday) as short names. */
export const weekdayLabels: Record<number, string> = {
    1: 'Mon',
    2: 'Tue',
    3: 'Wed',
    4: 'Thu',
    5: 'Fri',
    6: 'Sat',
    7: 'Sun',
};

/** "Mon–Fri", "Every day" or "Mon, Wed, Fri". */
export function formatWeekdays(weekdays: number[]): string {
    const days = [...weekdays].sort((a, b) => a - b);

    if (days.length === 7) {
        return 'Every day';
    }

    const isRun = days.every(
        (day, index) => index === 0 || day === days[index - 1] + 1,
    );

    if (isRun && days.length > 2) {
        return `${weekdayLabels[days[0]]}–${weekdayLabels[days[days.length - 1]]}`;
    }

    return days.map((day) => weekdayLabels[day]).join(', ');
}

/** "9:00 – 9:30 AM"-style range in the viewer's timezone. */
export function formatTimeRange(startsAt: string, endsAt: string): string {
    return `${formatTime(startsAt)} – ${formatTime(endsAt)}`;
}

/** Whole minutes between two ISO instants. */
export function minutesBetween(startsAt: string, endsAt: string): number {
    return Math.round(
        (new Date(endsAt).getTime() - new Date(startsAt).getTime()) / 60000,
    );
}
