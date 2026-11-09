<script setup lang="ts">
import { Moon, Sun } from '@lucide/vue';
import { Button } from '@/components/ui/button';
import {
    Tooltip,
    TooltipContent,
    TooltipProvider,
    TooltipTrigger,
} from '@/components/ui/tooltip';
import { useAppearance } from '@/composables/useAppearance';

/**
 * One-click light/dark switch. "System" stays available in Settings →
 * Appearance. Both icons are always rendered and CSS shows the right one, so
 * the server-rendered HTML never disagrees with the browser.
 */
defineOptions({ inheritAttrs: false });

const { updateAppearance } = useAppearance();

function toggle(): void {
    const next = document.documentElement.classList.contains('dark')
        ? 'light'
        : 'dark';
    const reducedMotion = window.matchMedia(
        '(prefers-reduced-motion: reduce)',
    ).matches;

    // Cross-fade the whole page where the View Transitions API exists.
    if (!reducedMotion && 'startViewTransition' in document) {
        document.startViewTransition(() => updateAppearance(next));

        return;
    }

    updateAppearance(next);
}
</script>

<template>
    <TooltipProvider :delay-duration="300">
        <Tooltip>
            <TooltipTrigger as-child>
                <Button
                    v-bind="$attrs"
                    variant="ghost"
                    size="icon"
                    class="rounded-full"
                    aria-label="Toggle dark mode"
                    @click="toggle"
                >
                    <Sun
                        class="size-[1.15rem] transition-transform duration-500 dark:hidden"
                    />
                    <Moon
                        class="hidden size-[1.15rem] transition-transform duration-500 dark:block"
                    />
                </Button>
            </TooltipTrigger>
            <TooltipContent>
                <span class="dark:hidden">Switch to dark mode</span>
                <span class="hidden dark:inline">Switch to light mode</span>
            </TooltipContent>
        </Tooltip>
    </TooltipProvider>
</template>
