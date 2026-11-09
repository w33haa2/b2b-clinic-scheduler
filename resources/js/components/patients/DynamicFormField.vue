<script setup lang="ts">
import { computed } from 'vue';
import DatePicker from '@/components/form/DatePicker.vue';
import FormField from '@/components/FormField.vue';
import { Checkbox } from '@/components/ui/checkbox';
import { Input } from '@/components/ui/input';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import { Textarea } from '@/components/ui/textarea';
import { cn } from '@/lib/utils';
import type { PatientFormField } from '@/types';

/**
 * One question from a clinic's patient form, as the control its type calls
 * for. The answer is whatever the API stores for that type: a string for
 * most, a list of strings for a multi-select, a boolean for a checkbox.
 */
const props = defineProps<{
    field: PatientFormField;
    error?: string;
    disabled?: boolean;
}>();

const value = defineModel<unknown>();

/** A dropdown item cannot have an empty value, so "no answer" needs a stand-in. */
const noAnswer = 'none';

/** Autofill hints for the built-in fields, so phones can fill them in. */
const autocompleteByKey: Record<string, string> = {
    first_name: 'given-name',
    middle_name: 'additional-name',
    last_name: 'family-name',
    email: 'email',
    phone: 'tel',
    address: 'street-address',
};

/** FormField prints the label as text, so the asterisk goes in the string. */
const label = computed(() =>
    props.field.is_required ? `${props.field.label} *` : props.field.label,
);

const placeholder = computed(() => props.field.placeholder ?? undefined);

const autocomplete = computed(() =>
    props.field.is_built_in ? autocompleteByKey[props.field.key] : undefined,
);

const inputType = computed(() => {
    switch (props.field.type) {
        case 'EMAIL':
            return 'email';
        case 'PHONE':
            return 'tel';
        default:
            return 'text';
    }
});

/** Single-value answers all travel as text; "" is "not answered". */
const text = computed<string>({
    get: () =>
        typeof value.value === 'string' || typeof value.value === 'number'
            ? String(value.value)
            : '',
    set: (next) => {
        value.value = next;
    },
});

/** Same as `text`, but the dropdown's stand-in means "clear this". */
const choice = computed<string>({
    get: () => text.value,
    set: (next) => {
        value.value = next === noAnswer ? '' : next;
    },
});

const chosen = computed<string[]>(() =>
    Array.isArray(value.value)
        ? (value.value as unknown[]).map((item) => String(item))
        : [],
);

const isChecked = computed(() => value.value === true || value.value === 1);

/** Toggling keeps the answer in the order the clinic listed the options. */
function toggleOption(option: string, checked: boolean): void {
    value.value = props.field.options.filter((item) =>
        item === option ? checked : chosen.value.includes(item),
    );
}

const optionRowClass = (selected: boolean): string =>
    cn(
        'flex items-center gap-3 rounded-md border px-3 py-2.5 text-left text-sm transition-colors',
        selected ? 'border-primary bg-accent/40' : 'hover:bg-accent/40',
        props.disabled && 'pointer-events-none opacity-50',
    );
</script>

<template>
    <FormField
        :label="label"
        :for="field.key"
        :error="error"
        :hint="field.help_text ?? undefined"
    >
        <Textarea
            v-if="field.type === 'TEXTAREA'"
            :id="field.key"
            v-model="text"
            rows="4"
            :placeholder="placeholder"
            :maxlength="field.validation.max_length"
            :autocomplete="autocomplete"
            :disabled="disabled"
        />

        <Input
            v-else-if="field.type === 'NUMBER'"
            :id="field.key"
            v-model="text"
            type="number"
            inputmode="decimal"
            :placeholder="placeholder"
            :min="field.validation.min"
            :max="field.validation.max"
            :disabled="disabled"
        />

        <DatePicker
            v-else-if="field.type === 'DATE'"
            :id="field.key"
            v-model="text"
            year-navigation
            :clearable="!field.is_required"
            :placeholder="placeholder ?? 'Pick a date'"
            :disabled="disabled"
        />

        <Select
            v-else-if="field.type === 'SELECT'"
            v-model="choice"
            :disabled="disabled"
        >
            <SelectTrigger :id="field.key" class="w-full">
                <SelectValue :placeholder="placeholder ?? 'Choose one'" />
            </SelectTrigger>
            <SelectContent>
                <SelectItem v-if="!field.is_required" :value="noAnswer">
                    —
                </SelectItem>
                <SelectItem
                    v-for="option in field.options"
                    :key="option"
                    :value="option"
                >
                    {{ option }}
                </SelectItem>
            </SelectContent>
        </Select>

        <div
            v-else-if="field.type === 'MULTISELECT'"
            role="group"
            :aria-label="field.label"
            class="grid gap-2"
        >
            <label
                v-for="option in field.options"
                :key="option"
                :class="
                    cn(
                        optionRowClass(chosen.includes(option)),
                        !disabled && 'cursor-pointer',
                    )
                "
            >
                <Checkbox
                    :model-value="chosen.includes(option)"
                    :disabled="disabled"
                    @update:model-value="
                        (checked) => toggleOption(option, checked === true)
                    "
                />
                {{ option }}
            </label>
        </div>

        <label
            v-else-if="field.type === 'CHECKBOX'"
            :class="
                cn(optionRowClass(isChecked), !disabled && 'cursor-pointer')
            "
        >
            <Checkbox
                :id="field.key"
                :model-value="isChecked"
                :disabled="disabled"
                @update:model-value="(checked) => (value = checked === true)"
            />
            {{ field.placeholder ?? field.label }}
        </label>

        <!-- No radio-group component in the kit: these are buttons that behave like one. -->
        <div
            v-else-if="field.type === 'RADIO'"
            role="radiogroup"
            :aria-label="field.label"
            class="grid gap-2"
        >
            <button
                v-for="option in field.options"
                :key="option"
                type="button"
                role="radio"
                :aria-checked="text === option"
                :disabled="disabled"
                :class="optionRowClass(text === option)"
                @click="value = option"
            >
                <span
                    :class="
                        cn(
                            'flex size-4 shrink-0 items-center justify-center rounded-full border',
                            text === option
                                ? 'border-primary bg-primary'
                                : 'border-input',
                        )
                    "
                >
                    <span
                        v-if="text === option"
                        class="size-1.5 rounded-full bg-primary-foreground"
                    />
                </span>
                {{ option }}
            </button>
        </div>

        <Input
            v-else
            :id="field.key"
            v-model="text"
            :type="inputType"
            :placeholder="placeholder"
            :maxlength="field.validation.max_length"
            :autocomplete="autocomplete"
            :disabled="disabled"
        />
    </FormField>
</template>
