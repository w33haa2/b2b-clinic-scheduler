<script setup lang="ts">
import {
    CalendarDays,
    Check,
    ListFilter,
    Pencil,
    Plus,
    Repeat,
    RotateCcw,
} from '@lucide/vue';
import { storeToRefs } from 'pinia';
import { computed } from 'vue';
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import { sessionStatusLabels } from '@/lib/format';
import { paymentStateLabels } from '@/lib/scheduleFilters';
import { cn } from '@/lib/utils';
import { useScheduleStore } from '@/stores/schedule';
import type { CalendarFilter, SessionStatus } from '@/types';

/**
 * Side panel filters of the schedule: quick filters (status, type, payment)
 * and the member's saved filters. Both live in the schedule store and
 * apply together.
 */
type Props = {
    savedFilters: CalendarFilter[];
    loading?: boolean;
};

defineProps<Props>();
const emit = defineEmits<{
    create: [];
    edit: [filter: CalendarFilter];
}>();

const scheduleStore = useScheduleStore();
const { quickFilters, savedFilterId } = storeToRefs(scheduleStore);

const statuses = Object.entries(sessionStatusLabels) as Array<
    [SessionStatus, string]
>;

const isFiltering = computed(
    () =>
        savedFilterId.value !== null ||
        quickFilters.value.statuses.length > 0 ||
        quickFilters.value.type !== 'all' ||
        quickFilters.value.payment !== 'all',
);

function toggleStatus(status: SessionStatus): void {
    const current = quickFilters.value.statuses;
    quickFilters.value.statuses = current.includes(status)
        ? current.filter((s) => s !== status)
        : [...current, status];
}

const typeOptions = [
    { value: 'regular', label: 'One-off sessions', icon: CalendarDays },
    { value: 'daily', label: 'Daily sessions', icon: Repeat },
] as const;

/** Whether events of this kind are shown (both are, unless one is filtered out). */
function shows(type: 'regular' | 'daily'): boolean {
    return (
        quickFilters.value.type === 'all' || quickFilters.value.type === type
    );
}

/** Hide or show one kind of event; at least one kind stays shown. */
function toggleType(type: 'regular' | 'daily'): void {
    const other = type === 'daily' ? 'regular' : 'daily';

    quickFilters.value.type = shows(type) ? other : 'all';
}

function applySaved(filter: CalendarFilter): void {
    savedFilterId.value = savedFilterId.value === filter.id ? null : filter.id;
}
</script>

<template>
    <div class="space-y-3">
        <div class="flex items-center justify-between px-1">
            <h2
                class="flex items-center gap-2 text-xs font-semibold tracking-wide text-muted-foreground uppercase"
            >
                <ListFilter class="size-3.5" />
                Filters
            </h2>
            <Button
                v-if="isFiltering"
                variant="ghost"
                size="sm"
                class="h-6 px-2 text-xs"
                @click="scheduleStore.resetFilters()"
            >
                <RotateCcw class="size-3" />
                Clear
            </Button>
        </div>

        <div
            class="flex flex-wrap gap-1"
            role="group"
            aria-label="Session status"
        >
            <button
                v-for="[status, label] in statuses"
                :key="status"
                type="button"
                :aria-pressed="quickFilters.statuses.includes(status)"
                :class="
                    cn(
                        'rounded-full border px-2.5 py-0.5 text-xs transition-colors',
                        quickFilters.statuses.includes(status)
                            ? 'border-teal-600 bg-teal-600 text-white'
                            : 'text-muted-foreground hover:bg-accent',
                    )
                "
                @click="toggleStatus(status)"
            >
                {{ label }}
            </button>
        </div>

        <!-- Which kinds of events the calendar shows -->
        <div class="space-y-1" role="group" aria-label="Show on the calendar">
            <label
                v-for="option in typeOptions"
                :key="option.value"
                class="flex cursor-pointer items-center gap-2.5 rounded-md px-1 py-1 text-sm hover:bg-accent"
            >
                <Checkbox
                    :model-value="shows(option.value)"
                    :disabled="
                        shows(option.value) && quickFilters.type !== 'all'
                    "
                    @update:model-value="toggleType(option.value)"
                />
                <component
                    :is="option.icon"
                    class="size-4 shrink-0 text-muted-foreground"
                />
                <span class="flex-1">{{ option.label }}</span>
            </label>
        </div>

        <div>
            <Select v-model="quickFilters.payment">
                <SelectTrigger size="sm" class="w-full" aria-label="Payment">
                    <SelectValue />
                </SelectTrigger>
                <SelectContent>
                    <SelectItem value="all">Any payment</SelectItem>
                    <SelectItem
                        v-for="(label, value) in paymentStateLabels"
                        :key="value"
                        :value="value"
                        >{{ label }}</SelectItem
                    >
                </SelectContent>
            </Select>
        </div>

        <div class="space-y-1">
            <p class="px-1 text-xs text-muted-foreground">My filters</p>
            <div
                v-for="filter in savedFilters"
                :key="filter.id"
                class="group flex items-center gap-1 rounded-md hover:bg-accent"
            >
                <button
                    type="button"
                    class="flex min-w-0 flex-1 items-center gap-2 px-2 py-1.5 text-left text-sm"
                    :aria-pressed="savedFilterId === filter.id"
                    @click="applySaved(filter)"
                >
                    <span
                        :class="
                            cn(
                                'flex size-4 shrink-0 items-center justify-center rounded border',
                                savedFilterId === filter.id &&
                                    'border-teal-600 bg-teal-600 text-white',
                            )
                        "
                    >
                        <Check
                            v-if="savedFilterId === filter.id"
                            class="size-3"
                        />
                    </span>
                    <span class="truncate">{{ filter.name }}</span>
                </button>
                <Button
                    variant="ghost"
                    size="icon"
                    class="size-7 opacity-0 group-hover:opacity-100 focus-visible:opacity-100"
                    :aria-label="`Edit filter ${filter.name}`"
                    @click="emit('edit', filter)"
                >
                    <Pencil class="size-3.5" />
                </Button>
            </div>
            <Button
                variant="ghost"
                size="sm"
                class="w-full justify-start text-muted-foreground"
                :disabled="loading"
                @click="emit('create')"
            >
                <Plus />
                New filter
            </Button>
        </div>
    </div>
</template>
