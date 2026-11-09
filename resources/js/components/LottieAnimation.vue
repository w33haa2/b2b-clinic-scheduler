<script setup lang="ts">
import type { AnimationItem } from 'lottie-web';
import { usePreferredReducedMotion } from '@vueuse/core';
import { onBeforeUnmount, onMounted, useTemplateRef } from 'vue';

type Props = {
    /** Parsed Lottie JSON, e.g. `import data from '@/animations/calendar-check.json'`. */
    animationData: object;
    loop?: boolean;
    autoplay?: boolean;
};

const props = withDefaults(defineProps<Props>(), {
    loop: true,
    autoplay: true,
});

const container = useTemplateRef<HTMLDivElement>('container');
const reducedMotion = usePreferredReducedMotion();
let animation: AnimationItem | null = null;

onMounted(async () => {
    // The player touches `document`, so it is loaded in the browser only and
    // kept out of the main bundle. The "light" build has no expression engine (no eval).
    const { default: lottie } =
        await import('lottie-web/build/player/lottie_light');

    if (!container.value) {
        return;
    }

    const isReduced = reducedMotion.value === 'reduce';

    animation = lottie.loadAnimation({
        container: container.value,
        renderer: 'svg',
        loop: props.loop && !isReduced,
        autoplay: props.autoplay && !isReduced,
        animationData: props.animationData,
    });

    if (isReduced) {
        animation.addEventListener('DOMLoaded', () =>
            animation?.goToAndStop(animation.totalFrames / 2, true),
        );
    }
});

onBeforeUnmount(() => {
    animation?.destroy();
    animation = null;
});
</script>

<template>
    <div ref="container" aria-hidden="true" />
</template>
