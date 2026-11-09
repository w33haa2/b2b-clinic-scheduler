<script setup lang="ts">
/**
 * Hairline workspace frame around a product mock on the landing page. The mock
 * is decorative, so it is exposed to assistive tech as one image with a label.
 */
withDefaults(
    defineProps<{
        label: string;
        title: string;
        clinic?: string;
    }>(),
    {
        clinic: 'Northside Clinic',
    },
);
</script>

<template>
    <figure
        role="img"
        :aria-label="label"
        class="overflow-hidden rounded-xl border bg-card shadow-[0_1px_0_0_var(--border),0_28px_60px_-24px_rgb(0_0_0/0.18)] dark:shadow-[0_1px_0_0_var(--border),0_32px_64px_-24px_rgb(0_0_0/0.65)]"
    >
        <div
            class="flex h-10 items-center gap-2.5 border-b bg-(--surface-sunken) px-3.5"
            aria-hidden="true"
        >
            <span class="flex gap-1.5">
                <span class="size-2.5 rounded-full bg-border" />
                <span class="size-2.5 rounded-full bg-border" />
                <span class="size-2.5 rounded-full bg-border" />
            </span>
            <div class="flex min-w-0 items-center gap-1.5 text-xs">
                <span class="hidden truncate text-muted-foreground sm:inline">
                    {{ clinic }}
                </span>
                <span class="hidden text-muted-foreground/50 sm:inline">/</span>
                <span class="truncate font-medium text-foreground/90">{{
                    title
                }}</span>
            </div>
            <span class="ml-auto flex items-center gap-2">
                <slot name="toolbar" />
            </span>
        </div>
        <div aria-hidden="true">
            <slot />
        </div>
    </figure>
</template>
