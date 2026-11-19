import { usePage } from '@inertiajs/vue3';
import { computed } from 'vue';
import type { ComputedRef } from 'vue';
import type { ClinicContext, ClinicSchedule } from '@/types';

export type UseClinicReturn = {
    context: ComputedRef<ClinicContext>;
    clinicId: ComputedRef<number>;
    isAdmin: ComputedRef<boolean>;
    can: (permission: string) => boolean;
    schedule: ComputedRef<ClinicSchedule>;
    /** The hours the calendar and the time pickers span, as whole hours. */
    openHours: ComputedRef<{ start: number; end: number }>;
};

/**
 * What the calendar falls back to when a clinic has closed every day. A grid
 * with no rows at all is harder to understand than an empty working day.
 */
const FALLBACK_HOURS = { start: 8, end: 17 };

/**
 * The clinic the current page belongs to and what the member may do there.
 * `can()` takes an API route name, e.g. `can('clinic.sessions.store')`. Hide
 * actions with it; the API still enforces every permission.
 */
export function useClinic(): UseClinicReturn {
    const page = usePage();

    const context = computed(() => {
        if (!page.props.clinicContext) {
            throw new Error('useClinic() can only be used on clinic pages.');
        }

        return page.props.clinicContext;
    });

    const isAdmin = computed(() => context.value.member.is_admin);

    function can(permission: string): boolean {
        return (
            isAdmin.value ||
            context.value.member.permissions.includes(permission)
        );
    }

    const schedule = computed(() => context.value.clinic.schedule);

    const openHours = computed(() => {
        const { opens_at: opens, closes_at: closes } = schedule.value;

        if (!opens || !closes) {
            return FALLBACK_HOURS;
        }

        // Floor the opening and ceil the closing, so a 08:30-17:30 clinic
        // still shows whole hours either side of its day.
        const [openHour] = opens.split(':').map(Number);
        const [closeHour, closeMinute] = closes.split(':').map(Number);

        return {
            start: openHour,
            end: Math.min(24, closeMinute > 0 ? closeHour + 1 : closeHour),
        };
    });

    return {
        context,
        clinicId: computed(() => context.value.clinic.id),
        isAdmin,
        can,
        schedule,
        openHours,
    };
}
