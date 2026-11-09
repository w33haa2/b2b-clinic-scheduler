import { defineStore } from 'pinia';
import { ref } from 'vue';
import type { QuickFilters } from '@/lib/scheduleFilters';
import { defaultQuickFilters } from '@/lib/scheduleFilters';

export type CalendarView =
    | 'timeGridDay'
    | 'timeGridWeek'
    | 'dayGridMonth'
    | 'listWeek';

/**
 * Schedule page preferences that should survive navigating away and back:
 * the calendar view, the date being looked at, which members' sessions
 * are hidden (like unticking calendars in Google Calendar), the quick
 * filters and the saved filter being applied.
 */
export const useScheduleStore = defineStore('schedule', () => {
    const view = ref<CalendarView>('timeGridWeek');
    const focusDate = ref<string | null>(null);
    const hiddenMemberIds = ref<number[]>([]);
    const quickFilters = ref<QuickFilters>(defaultQuickFilters());
    const savedFilterId = ref<number | null>(null);

    function resetFilters(): void {
        quickFilters.value = defaultQuickFilters();
        savedFilterId.value = null;
    }

    function toggleMember(memberId: number): void {
        hiddenMemberIds.value = hiddenMemberIds.value.includes(memberId)
            ? hiddenMemberIds.value.filter((id) => id !== memberId)
            : [...hiddenMemberIds.value, memberId];
    }

    function isMemberVisible(memberId: number): boolean {
        return !hiddenMemberIds.value.includes(memberId);
    }

    return {
        view,
        focusDate,
        hiddenMemberIds,
        quickFilters,
        savedFilterId,
        toggleMember,
        isMemberVisible,
        resetFilters,
    };
});
