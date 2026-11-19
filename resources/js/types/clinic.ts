/**
 * Shared Inertia props that describe where the user is working.
 */
export type ClinicSummary = {
    id: number;
    name: string;
};

/**
 * When the clinic is open. `opens_at` / `closes_at` are the widest of the
 * weekday and weekend windows, which is what the calendar grid and the time
 * pickers span. Times are "HH:MM" in the clinic's timezone.
 */
export type ClinicSchedule = {
    weekday_opens_at: string;
    weekday_closes_at: string;
    weekend_opens_at: string;
    weekend_closes_at: string;
    saturday_enabled: boolean;
    sunday_enabled: boolean;
    allow_outside_hours: boolean;
    opens_at: string | null;
    closes_at: string | null;
    /** ISO weekdays (1-7) the clinic opens on. */
    open_weekdays: number[];
    updated_at: string | null;
};

export type ClinicContext = {
    clinic: ClinicSummary & { timezone: string; schedule: ClinicSchedule };
    member: {
        id: number;
        is_admin: boolean;
        permissions: string[];
    };
};
