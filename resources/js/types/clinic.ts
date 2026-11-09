/**
 * Shared Inertia props that describe where the user is working.
 */
export type ClinicSummary = {
    id: number;
    name: string;
};

export type ClinicContext = {
    clinic: ClinicSummary & { timezone: string };
    member: {
        id: number;
        is_admin: boolean;
        permissions: string[];
    };
};
