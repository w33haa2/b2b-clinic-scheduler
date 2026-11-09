<script setup lang="ts">
import type { HTMLAttributes } from 'vue';

defineOptions({
    inheritAttrs: false,
});

type Props = {
    className?: HTMLAttributes['class'];
};

defineProps<Props>();
</script>

<template>
    <!-- Calendar page with a medical cross: the app's mark. Filled with currentColor. -->
    <svg
        xmlns="http://www.w3.org/2000/svg"
        viewBox="0 0 40 42"
        :class="className"
        v-bind="$attrs"
    >
        <path
            fill-rule="evenodd"
            clip-rule="evenodd"
            d="M12 1a2 2 0 0 1 2 2v2h12V3a2 2 0 1 1 4 0v2h2a6 6 0 0 1 6 6v24a6 6 0 0 1-6 6H8a6 6 0 0 1-6-6V11a6 6 0 0 1 6-6h2V3a2 2 0 0 1 2-2Zm-4 14v20a2 2 0 0 0 2 2h20a2 2 0 0 0 2-2V15H8Zm9.5 4h5v4.5H27v5h-4.5V33h-5v-4.5H13v-5h4.5V19Z"
        />
    </svg>
</template>
