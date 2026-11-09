<script setup lang="ts">
import calendarCheck from '@/animations/calendar-check.json';
import LottieAnimation from '@/components/LottieAnimation.vue';

type Props = {
    title: string;
    description?: string;
};

defineProps<Props>();
</script>

<template>
    <div
        class="animate__animated animate__fadeIn flex flex-col items-center justify-center gap-2 px-6 py-12 text-center"
    >
        <LottieAnimation :animation-data="calendarCheck" class="size-28" />
        <h3 class="font-medium">{{ title }}</h3>
        <p v-if="description" class="max-w-sm text-sm text-muted-foreground">
            {{ description }}
        </p>
        <div v-if="$slots.default" class="mt-2">
            <slot />
        </div>
    </div>
</template>
