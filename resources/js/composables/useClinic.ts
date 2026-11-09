import { usePage } from '@inertiajs/vue3';
import { computed } from 'vue';
import type { ComputedRef } from 'vue';
import type { ClinicContext } from '@/types';

export type UseClinicReturn = {
    context: ComputedRef<ClinicContext>;
    clinicId: ComputedRef<number>;
    isAdmin: ComputedRef<boolean>;
    can: (permission: string) => boolean;
};

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

    return {
        context,
        clinicId: computed(() => context.value.clinic.id),
        isAdmin,
        can,
    };
}
