<script setup lang="ts">
import InputError from '@/components/InputError.vue';
import { Label } from '@/components/ui/label';

type Props = {
    label: string;
    for?: string;
    error?: string;
    hint?: string;
};

defineProps<Props>();
</script>

<template>
    <div class="grid gap-2">
        <Label :for="$props.for">{{ label }}</Label>
        <slot />
        <p v-if="hint && !error" class="text-xs text-muted-foreground">
            {{ hint }}
        </p>
        <InputError :message="error" />
    </div>
</template>
