<script setup lang="ts">
import type { DateValue } from '@internationalized/date';
import { getLocalTimeZone, parseDate, today } from '@internationalized/date';
import { CalendarDays, X } from '@lucide/vue';
import { computed, ref } from 'vue';
import { Button } from '@/components/ui/button';
import { Calendar } from '@/components/ui/calendar';
import {
    Popover,
    PopoverContent,
    PopoverTrigger,
} from '@/components/ui/popover';
import { cn } from '@/lib/utils';

/**
 * Styled date input. v-model is an ISO date string ("2026-11-04") or "".
 * Use `year-navigation` for dates far from today (e.g. birth dates) to get
 * month and year dropdowns in the calendar header.
 */
type Props = {
    id?: string;
    placeholder?: string;
    clearable?: boolean;
    yearNavigation?: boolean;
    /** Latest selectable date, ISO ("2026-11-04") or "today". */
    max?: string;
    disabled?: boolean;
    class?: string;
};

const props = withDefaults(defineProps<Props>(), {
    placeholder: 'Pick a date',
    clearable: false,
    yearNavigation: false,
});

const model = defineModel<string>({ default: '' });
const open = ref(false);

const toDateValue = (value: string | undefined): DateValue | undefined => {
    if (!value) {
        return undefined;
    }

    return value === 'today'
        ? today(getLocalTimeZone())
        : parseDate(value.slice(0, 10));
};

const selected = computed(() => toDateValue(model.value));
const maxValue = computed(() => toDateValue(props.max));

const label = computed(() =>
    selected.value
        ? selected.value
              .toDate(getLocalTimeZone())
              .toLocaleDateString(undefined, {
                  day: 'numeric',
                  month: 'short',
                  year: 'numeric',
              })
        : '',
);

function pick(value: DateValue | undefined): void {
    model.value = value ? value.toString() : '';
    open.value = false;
}
</script>

<template>
    <Popover v-model:open="open">
        <PopoverTrigger as-child>
            <Button
                :id="id"
                type="button"
                variant="outline"
                :disabled="disabled"
                :class="
                    cn(
                        'w-full justify-start gap-2 px-3 font-normal',
                        !label && 'text-muted-foreground',
                        props.class,
                    )
                "
            >
                <CalendarDays class="size-4 text-muted-foreground" />
                <span class="flex-1 truncate text-left">{{
                    label || placeholder
                }}</span>
                <span
                    v-if="clearable && label"
                    role="button"
                    tabindex="-1"
                    aria-label="Clear date"
                    class="-mr-1 rounded-sm p-0.5 text-muted-foreground hover:bg-muted hover:text-foreground"
                    @click.stop="model = ''"
                >
                    <X class="size-3.5" />
                </span>
            </Button>
        </PopoverTrigger>
        <PopoverContent class="w-auto p-0" align="start">
            <Calendar
                :model-value="selected"
                :default-placeholder="
                    selected ?? maxValue ?? today(getLocalTimeZone())
                "
                :max-value="maxValue"
                :layout="yearNavigation ? 'month-and-year' : undefined"
                initial-focus
                @update:model-value="
                    (value) => pick(value as DateValue | undefined)
                "
            />
        </PopoverContent>
    </Popover>
</template>
