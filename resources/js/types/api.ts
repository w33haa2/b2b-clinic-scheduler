/**
 * Shapes returned by the JSON API (app/Http/Resources). Money is always a
 * string with two decimals; times are ISO 8601 in UTC.
 */

export type SessionStatus = 'PENDING' | 'ONGOING' | 'DONE' | 'CANCELLED';
export type PaymentStatus = 'PENDING' | 'DONE' | 'REFUNDED';
export type TransactionType = 'INBOUND' | 'REVERSED';
export type Sex = 'male' | 'female' | 'other';
export type AttendanceStatus = 'PRESENT' | 'ABSENT' | 'LATE' | 'EXCUSED';
export type PayoutStatus = 'DRAFT' | 'PAID' | 'CANCELLED';
export type DiscountType = 'PERCENTAGE' | 'FIXED';
/** Whose cut a discount comes out of. */
export type DiscountTarget = 'CLINIC' | 'WORKER';
export type DiscountScope = 'CLINIC_WIDE' | 'PERSONAL';
export type PatientFormFieldType =
    | 'TEXT'
    | 'TEXTAREA'
    | 'NUMBER'
    | 'DATE'
    | 'SELECT'
    | 'MULTISELECT'
    | 'CHECKBOX'
    | 'RADIO'
    | 'EMAIL'
    | 'PHONE';
export type ProgressRating =
    | 'BASELINE'
    | 'IMPROVED'
    | 'UNCHANGED'
    | 'DECLINED'
    | 'RESOLVED';

export type ApiUser = {
    id: number;
    name: string;
    email: string;
    is_super_admin: boolean;
    created_at: string;
};

export type Clinic = {
    id: number;
    name: string;
    address: string | null;
    timezone: string;
    created_at: string;
    updated_at: string;
};

export type ClinicRole = {
    id: number;
    name: string;
    key: 'admin' | 'worker' | null;
    is_system: boolean;
    permissions?: string[];
};

export type ClinicMember = {
    id: number;
    user?: ApiUser;
    roles?: ClinicRole[];
    /** The member's own cut of what they bill; null = the clinic default. */
    payout_share_percentage: string | null;
    joined_at: string;
};

export type Permission = {
    id: number;
    name: string;
};

export type Patient = {
    id: number;
    first_name: string;
    middle_name: string | null;
    last_name: string;
    birth_date: string | null;
    sex: Sex | null;
    email: string | null;
    phone: string | null;
    address: string | null;
    notes: string | null;
    /** Answers to the clinic's own form fields, keyed by field key. */
    fields?: Record<string, unknown>;
    /** Answers to fields that have since been removed from the form. */
    archived_fields?: Array<{ key: string; label: string; value: unknown }>;
    created_at: string;
    updated_at: string;
};

export type PatientFormField = {
    id: number;
    key: string;
    label: string;
    placeholder: string | null;
    help_text: string | null;
    type: PatientFormFieldType;
    options: string[];
    validation: { max_length?: number; min?: number; max?: number };
    is_required: boolean;
    section: string;
    position: number;
    /** A column on `patients`: it can be renamed, never removed or retyped. */
    is_built_in: boolean;
    is_archived: boolean;
    archived_at: string | null;
    answer_count?: number;
    created_at: string;
    updated_at: string;
};

export type PatientFormSection = {
    name: string;
    fields: PatientFormField[];
};

export type PatientForm = {
    clinic: { id: number; name: string };
    sections: PatientFormSection[];
    fields: PatientFormField[];
};

export type PatientFormFieldUsage = {
    field_id: number;
    answer_count: number;
    is_built_in: boolean;
    can_be_removed: boolean;
};

/** A field suggested by the import wizard. Nothing is saved until confirmed. */
export type ExtractedFormField = {
    key: string;
    label: string;
    type: PatientFormFieldType;
    options: string[];
    confidence: 'high' | 'medium' | 'low';
    source_line: string | null;
    section: string | null;
    is_required: boolean;
    existing_field_id: number | null;
    existing_label: string | null;
    existing_is_archived: boolean;
};

export type FormExtraction = {
    source: string;
    fields: ExtractedFormField[];
    warning: string | null;
};

export type ProgressMetric = {
    id: number;
    name: string;
    value: string;
    unit: string | null;
};

export type PatientProgressNote = {
    id: number;
    patient_id: number;
    patient?: Patient;
    recorded_on: string;
    progress: ProgressRating;
    progress_label: string;
    /** 1 better, 0 flat, -1 worse. */
    direction: number;
    summary: string;
    body: string | null;
    plan: string | null;
    author?: ClinicMember;
    session?: { id: number; starts_at: string; label: string } | null;
    metrics?: ProgressMetric[];
    created_at: string;
    updated_at: string;
};

export type ProgressTrend = {
    notes: number;
    improved: number;
    unchanged: number;
    declined: number;
    latest: ProgressRating | null;
    direction: number;
};

export type MetricSeries = {
    name: string;
    unit: string | null;
    points: Array<{ date: string; value: string }>;
};

export type PatientProgressNotePage = Paginated<PatientProgressNote> & {
    meta: Paginated<PatientProgressNote>['meta'] & {
        trend: ProgressTrend;
        metrics: MetricSeries[];
    };
};

export type PatientHistoryEntry = {
    type: 'session' | 'progress_note' | 'record_change';
    at: string;
    title: string;
    status?: SessionStatus;
    held_by?: string | null;
    payment_status?: PaymentStatus;
    amount?: string | null;
    items?: string[];
    progress?: ProgressRating;
    progress_label?: string;
    direction?: number;
    author?: string | null;
    note_id?: number;
    changes?: string[];
};

export type PatientHistory = {
    patient_id: number;
    entries: PatientHistoryEntry[];
};

export type PatientFormInvitation = {
    id: number;
    name: string | null;
    email: string | null;
    status: 'pending' | 'submitted' | 'revoked' | 'expired';
    patient_id: number | null;
    patient?: Patient;
    created_by?: ClinicMember;
    expires_at: string;
    submitted_at: string | null;
    revoked_at: string | null;
    created_at: string;
    /** Only present on the response that issues the link. */
    url?: string;
};

export type LineItem = {
    id: number;
    item_code: string;
    name: string;
    price: string;
    created_at: string;
    updated_at: string;
};

export type SessionLineItem = {
    id: number;
    session_member_id: number;
    line_item?: LineItem;
    quantity: number;
    unit_price: string;
    /** Before the discount: quantity x unit_price. */
    gross_amount: string;
    discount_amount: string;
    discount_target: DiscountTarget | null;
    discount?: Discount;
    /** What the patient owes: gross_amount - discount_amount. */
    amount: string;
    invoice_id?: number | null;
    payout_id?: number | null;
};

export type SessionMember = {
    id: number;
    patient?: Patient;
    payment_id?: number | null;
    payment_status?: PaymentStatus;
    /** The plotted time slot on a day of a daily session; null until plotted. */
    starts_at: string | null;
    ends_at: string | null;
    /** Changed by hand for this day only; regular plan changes skip it. */
    customised: boolean;
    line_items?: SessionLineItem[];
};

export type ClinicSession = {
    id: number;
    name: string | null;
    starts_at: string;
    ends_at: string;
    status: SessionStatus;
    clinic_user?: ClinicMember;
    /** Set when the session is one day of a daily session. */
    daily_session?: { id: number; name: string } | null;
    occurs_on: string | null;
    members?: SessionMember[];
    created_at: string;
    updated_at: string;
};

/** Free time inside a daily session's day, for plotting patients. */
export type Vacancy = { starts_at: string; ends_at: string; minutes: number };

/** An enrolled patient and their regular plan. */
export type DailySessionEnrollment = {
    id: number;
    patient?: Patient;
    line_item?: LineItem;
    unit_price: string;
    /** Usual slot, "HH:MM" in the clinic's timezone; null without one. */
    start_time: string | null;
    duration_minutes: number | null;
    enrolled_at: string;
};

export type DailySession = {
    id: number;
    name: string;
    clinic_user?: ClinicMember;
    line_item?: LineItem;
    unit_price: string;
    /** "08:00", in the clinic's timezone. */
    start_time: string;
    end_time: string;
    /** ISO weekdays: 1 = Monday … 7 = Sunday. */
    weekdays: number[];
    starts_on: string;
    ends_on: string | null;
    timezone?: string;
    patients_count?: number;
    enrollments?: DailySessionEnrollment[];
    created_at: string;
    updated_at: string;
};

export type UpcomingDay = {
    date: string;
    starts_at: string;
    ends_at: string;
    clinic_session_id: number | null;
    status: SessionStatus | null;
};

export type Payment = {
    id: number;
    status: PaymentStatus;
    is_past_due?: boolean;
    amount: string | null;
    paid_at: string | null;
    refunded_at: string | null;
    patient?: Patient;
    session?: ClinicSession;
    session_member_id: number;
    line_items?: SessionLineItem[];
    created_at: string;
};

export type PaymentSummary = Record<
    'outstanding' | 'past_due' | 'paid' | 'refunded',
    { count: number; total: string }
>;

export type CalendarFilterField =
    | 'staff'
    | 'status'
    | 'type'
    | 'patient'
    | 'payment'
    | 'name';
export type CalendarFilterOperator = 'is' | 'is_not' | 'contains';

export type CalendarFilterCondition = {
    field: CalendarFilterField;
    operator: CalendarFilterOperator;
    values: string[];
};

export type CalendarFilterRules = {
    match: 'all' | 'any';
    conditions: CalendarFilterCondition[];
};

export type CalendarFilter = {
    id: number;
    name: string;
    rules: CalendarFilterRules;
    created_at: string;
    updated_at: string;
};

export type Transaction = {
    id: number;
    type: TransactionType;
    amount: string;
    payment_id: number;
    patient?: Patient;
    session_line_item?: SessionLineItem;
    payout_id?: number | null;
    recorded_by?: ClinicMember;
    created_at: string;
};

export type Attendance = {
    id: number;
    member_id: number;
    member?: ClinicMember;
    /** A date in the clinic's timezone, "YYYY-MM-DD". */
    work_date: string;
    status: AttendanceStatus;
    counts_as_worked: boolean;
    checked_in_at: string | null;
    checked_out_at: string | null;
    worked_minutes: number | null;
    notes: string | null;
    recorded_by?: ClinicMember;
    created_at: string;
    updated_at: string;
};

export type AttendancePage = Paginated<Attendance> & {
    meta: Paginated<Attendance>['meta'] & {
        summary: Record<AttendanceStatus, number>;
    };
};

export type Discount = {
    id: number;
    name: string;
    scope: DiscountScope;
    owner_id: number | null;
    owner?: ClinicMember;
    type: DiscountType;
    target: DiscountTarget;
    value: string;
    is_active: boolean;
    created_at: string;
    updated_at: string;
};

export type ClinicPayoutSettings = {
    worker_share_percentage: string;
    clinic_share_percentage: string;
    include_pending_payments: boolean;
    require_attendance: boolean;
    updated_at: string | null;
};

export type PayoutSettingsMember = ClinicMember & {
    effective_share_percentage: string;
    uses_override: boolean;
};

export type PayoutSettingsPage = {
    settings: ClinicPayoutSettings;
    members: PayoutSettingsMember[];
};

export type PayoutItem = {
    id: number;
    payout_id: number;
    session_line_item_id: number;
    share_percentage: string;
    gross_amount: string;
    discount_amount: string;
    worker_amount: string;
    clinic_amount: string;
    net_amount: string;
    /** The member was paid before the patient was. */
    was_pending: boolean;
    settled_at: string | null;
    is_unsettled: boolean;
    service?: string | null;
    quantity?: number;
    patient?: Patient | null;
    payment_status?: PaymentStatus;
    session?: { id: number; starts_at: string; label: string } | null;
};

export type Payout = {
    id: number;
    reference: string;
    number: number;
    member_id: number;
    member?: ClinicMember;
    period_start: string;
    period_end: string;
    status: PayoutStatus;
    share_percentage: string;
    gross_amount: string;
    discount_total: string;
    worker_amount: string;
    clinic_amount: string;
    /** Part of worker_amount that came from patients who had not paid. */
    pending_amount: string;
    unsettled_amount?: string;
    notes: string | null;
    generated_by?: ClinicMember;
    items?: PayoutItem[];
    paid_at: string | null;
    created_at: string;
};

export type MoneyTotal = { count: number; total: string };

export type PayoutSummary = Record<
    'draft' | 'paid' | 'cancelled' | 'unsettled',
    MoneyTotal
>;

export type PayoutPage = Paginated<Payout> & {
    meta: Paginated<Payout>['meta'] & { summary: PayoutSummary };
};

export type PayoutPreviewLine = {
    session_line_item_id: number;
    clinic_session_id: number;
    session: string;
    occurred_on: string;
    patient: string;
    service: string;
    quantity: number;
    unit_price: string;
    gross_amount: string;
    discount_amount: string;
    discount: string | null;
    discount_target: DiscountTarget | null;
    worker_amount: string;
    clinic_amount: string;
    payment_status: PaymentStatus;
    is_pending: boolean;
};

export type PayoutPreview = {
    member: ClinicMember;
    period_start: string;
    period_end: string;
    share_percentage: string;
    uses_member_override: boolean;
    settings: ClinicPayoutSettings;
    lines: PayoutPreviewLine[];
    totals: {
        items: number;
        gross_amount: string;
        discount_total: string;
        worker_amount: string;
        clinic_amount: string;
        pending_amount: string;
    };
    attendance: { worked: number; not_worked: number; unrecorded: number };
    skipped: { refunded: number; unpaid: number; not_worked: number };
};

export type ReceivablePage = Paginated<PayoutItem> & {
    meta: Paginated<PayoutItem>['meta'] & {
        summary: Record<'advanced' | 'settled' | 'outstanding', MoneyTotal>;
        by_member: Array<{
            clinic_user_id: number;
            name: string;
            count: number;
            total: string;
        }>;
    };
};

export type Invoice = {
    id: number;
    number: number;
    reference: string;
    patient?: Patient;
    issued_at: string;
    notes: string | null;
    items?: SessionLineItem[];
    total?: string;
    created_at: string;
    updated_at: string;
};

export type ClinicInvitation = {
    id: number;
    email: string;
    status: 'pending' | 'accepted' | 'revoked' | 'expired';
    role?: { id: number; name: string };
    invited_by?: { id: number; name: string } | null;
    expires_at: string;
    accepted_at: string | null;
    revoked_at: string | null;
    created_at: string;
};

export type Paginated<T> = {
    data: T[];
    links: {
        first: string | null;
        last: string | null;
        prev: string | null;
        next: string | null;
    };
    meta: {
        current_page: number;
        last_page: number;
        per_page: number;
        total: number;
        from: number | null;
        to: number | null;
    };
};

export type Wrapped<T> = { data: T };

export type ActivityChange = { old: unknown; new: unknown };

export type ActivityLog = {
    id: number;
    action: string;
    category: { value: string; label: string } | null;
    description: string;
    subject_label: string | null;
    changes: Record<string, ActivityChange>;
    actor?: {
        id: number;
        name: string;
        email: string;
        is_super_admin: boolean;
    } | null;
    clinic?: { id: number; name: string } | null;
    ip_address: string | null;
    created_at: string;
};

export type ActivityFilterOption = { value: string; label: string };

export type ActivityLogPage = Paginated<ActivityLog> & {
    meta: Paginated<ActivityLog>['meta'] & {
        filters: {
            categories: ActivityFilterOption[];
            actors?: Array<{
                id: number;
                name: string;
                is_super_admin: boolean;
            }>;
            clinics?: Array<{ id: number; name: string }>;
        };
    };
};
