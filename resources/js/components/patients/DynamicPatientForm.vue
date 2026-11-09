<script setup lang="ts">
import DynamicFormField from '@/components/patients/DynamicFormField.vue';
import type { PatientFormSection } from '@/types';

/**
 * A clinic's whole patient form, section by section. The answers live in the
 * caller's object, keyed by field key — the shape the API reads and writes —
 * so the same component serves the staff dialog and the public intake page.
 */
defineProps<{
    sections: PatientFormSection[];
    errors: Record<string, string | undefined>;
    disabled?: boolean;
}>();

const values = defineModel<Record<string, unknown>>({ required: true });
</script>

<template>
    <div class="grid gap-8">
        <section
            v-for="section in sections"
            :key="section.name"
            class="grid gap-4"
        >
            <h3
                class="border-b pb-2 text-xs font-medium tracking-wide text-muted-foreground uppercase"
            >
                {{ section.name }}
            </h3>
            <div class="grid gap-4">
                <DynamicFormField
                    v-for="field in section.fields"
                    :key="field.id"
                    :model-value="values[field.key]"
                    :field="field"
                    :error="errors[field.key]"
                    :disabled="disabled"
                    @update:model-value="(value) => (values[field.key] = value)"
                />
            </div>
        </section>
    </div>
</template>
