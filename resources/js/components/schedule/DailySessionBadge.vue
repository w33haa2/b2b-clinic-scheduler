<script setup lang="ts">
import { Repeat } from '@lucide/vue';
import {
    Tooltip,
    TooltipContent,
    TooltipProvider,
    TooltipTrigger,
} from '@/components/ui/tooltip';

/**
 * Marks a calendar event as a patient's plotted time in a daily session.
 * Hovering (or focusing) it explains what the event is.
 */
type Props = {
    /** The daily session's name. */
    name: string;
    /** The staff member holding it. */
    holder?: string | null;
};

defineProps<Props>();
</script>

<template>
    <TooltipProvider :delay-duration="150">
        <Tooltip>
            <TooltipTrigger as-child>
                <span
                    tabindex="0"
                    class="inline-flex size-4 shrink-0 items-center justify-center rounded bg-teal-600 text-white dark:bg-teal-500"
                    :aria-label="`Daily session: ${name}`"
                >
                    <Repeat class="size-2.5" />
                </span>
            </TooltipTrigger>
            <TooltipContent side="top" class="max-w-60 text-xs">
                <p class="font-medium">Daily session: {{ name }}</p>
                <p class="opacity-80">
                    This patient's plotted time for the day<template
                        v-if="holder"
                    >
                        with {{ holder }}</template
                    >. Click to plot or rearrange the day.
                </p>
            </TooltipContent>
        </Tooltip>
    </TooltipProvider>
</template>
