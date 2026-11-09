<script setup lang="ts">
import { useHttp } from '@inertiajs/vue3';
import { ListFilter, Plus, Trash2, X } from '@lucide/vue';
import { computed, ref, watch } from 'vue';
import FormField from '@/components/FormField.vue';
import InputError from '@/components/InputError.vue';
import PatientPicker from '@/components/PatientPicker.vue';
import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import { Spinner } from '@/components/ui/spinner';
import { useClinic } from '@/composables/useClinic';
import { sessionStatusLabels } from '@/lib/format';
import {
    filterFields,
    operatorLabels,
    paymentStateLabels,
    sessionTypeLabels,
} from '@/lib/scheduleFilters';
import { cn } from '@/lib/utils';
import { store, update } from '@/routes/clinic/calendar-filters';
import type {
    CalendarFilter,
    CalendarFilterCondition,
    CalendarFilterField,
    CalendarFilterRules,
    ClinicMember,
    Patient,
    Wrapped,
} from '@/types';

type Props = {
    /** Editing a saved filter; null to create one. */
    filter?: CalendarFilter | null;
    members: ClinicMember[];
    /** Names of patients seen on the schedule, to label saved patient conditions. */
    knownPatients: Map<number, string>;
};

const props = defineProps<Props>();
const open = defineModel<boolean>('open', { required: true });
const emit = defineEmits<{
    saved: [filter: CalendarFilter];
    delete: [filter: CalendarFilter];
}>();

const { clinicId } = useClinic();

type FilterForm = { name: string; rules: CalendarFilterRules };

const form = useHttp<FilterForm, Wrapped<CalendarFilter>>({
    name: '',
    rules: { match: 'all', conditions: [] },
});

/** Patients picked per condition, so chips can show names. */
const pickedPatients = ref<Record<number, Patient[]>>({});

watch(open, (isOpen) => {
    if (!isOpen) {
        return;
    }

    const rules: CalendarFilterRules = props.filter
        ? structuredClone(props.filter.rules)
        : {
              match: 'all',
              conditions: [
                  { field: 'payment', operator: 'is', values: ['unpaid'] },
              ],
          };

    pickedPatients.value = Object.fromEntries(
        rules.conditions.map((condition, index) => [
            index,
            condition.field === 'patient'
                ? condition.values.map((id) => {
                      const name =
                          props.knownPatients.get(Number(id)) ??
                          `Patient #${id}`;
                      const [first_name, ...rest] = name.split(' ');

                      return {
                          id: Number(id),
                          first_name,
                          last_name: rest.join(' '),
                      } as Patient;
                  })
                : [],
        ]),
    );
    form.defaults({ name: props.filter?.name ?? '', rules })
        .reset()
        .clearErrors();
});

const valueOptions = computed<
    Record<CalendarFilterField, Array<{ value: string; label: string }>>
>(() => ({
    staff: props.members.map((member) => ({
        value: String(member.id),
        label: member.user?.name ?? `Staff #${member.id}`,
    })),
    status: Object.entries(sessionStatusLabels).map(([value, label]) => ({
        value,
        label,
    })),
    type: Object.entries(sessionTypeLabels).map(([value, label]) => ({
        value,
        label,
    })),
    payment: Object.entries(paymentStateLabels).map(([value, label]) => ({
        value,
        label,
    })),
    patient: [],
    name: [],
}));

function addCondition(): void {
    const index = form.rules.conditions.length;
    pickedPatients.value[index] = [];
    form.rules.conditions.push({ field: 'status', operator: 'is', values: [] });
}

function removeCondition(index: number): void {
    form.rules.conditions.splice(index, 1);
    const shifted: Record<number, Patient[]> = {};

    form.rules.conditions.forEach((_, position) => {
        shifted[position] =
            pickedPatients.value[position >= index ? position + 1 : position] ??
            [];
    });
    pickedPatients.value = shifted;
}

function changeField(
    condition: CalendarFilterCondition,
    index: number,
    field: CalendarFilterField,
): void {
    condition.field = field;
    condition.operator = filterFields.find(
        (option) => option.value === field,
    )!.operators[0];
    condition.values = [];
    pickedPatients.value[index] = [];
}

function toggleValue(condition: CalendarFilterCondition, value: string): void {
    if (condition.field === 'type') {
        condition.values = [value];

        return;
    }

    condition.values = condition.values.includes(value)
        ? condition.values.filter((v) => v !== value)
        : [...condition.values, value];
}

function setPatients(
    condition: CalendarFilterCondition,
    index: number,
    patients: Patient[],
): void {
    pickedPatients.value[index] = patients;
    condition.values = patients.map((patient) => String(patient.id));
}

async function submit(): Promise<void> {
    const options = {
        onSuccess: (response: Wrapped<CalendarFilter>) => {
            emit('saved', response.data);
            open.value = false;
        },
    };

    if (props.filter) {
        await form.put(
            update.url({
                clinic: clinicId.value,
                calendarFilter: props.filter.id,
            }),
            options,
        );
    } else {
        await form.post(store.url(clinicId.value), options);
    }
}

const conditionError = (index: number): string | undefined =>
    Object.entries(form.errors as Record<string, string | undefined>).find(
        ([key]) => key.startsWith(`rules.conditions.${index}`),
    )?.[1];
</script>

<template>
    <Dialog v-model:open="open">
        <DialogContent class="max-h-[90svh] overflow-y-auto sm:max-w-2xl">
            <DialogHeader>
                <DialogTitle class="flex items-center gap-2">
                    <ListFilter class="size-5 text-teal-600" />
                    {{ filter ? 'Edit filter' : 'New filter' }}
                </DialogTitle>
                <DialogDescription>
                    Build your own view of the schedule. Saved filters are only
                    visible to you.
                </DialogDescription>
            </DialogHeader>

            <form
                id="calendar-filter-form"
                class="grid gap-4"
                @submit.prevent="submit"
            >
                <FormField
                    label="Name"
                    for="filter-name"
                    :error="form.errors.name"
                >
                    <Input
                        id="filter-name"
                        v-model="form.name"
                        placeholder="e.g. My unpaid daily patients"
                    />
                </FormField>

                <div class="flex items-center gap-2 text-sm">
                    Show sessions that match
                    <Select v-model="form.rules.match">
                        <SelectTrigger
                            size="sm"
                            class="w-24"
                            aria-label="Match"
                        >
                            <SelectValue />
                        </SelectTrigger>
                        <SelectContent>
                            <SelectItem value="all">all</SelectItem>
                            <SelectItem value="any">any</SelectItem>
                        </SelectContent>
                    </Select>
                    of these conditions:
                </div>

                <ol class="space-y-3">
                    <li
                        v-for="(condition, index) in form.rules.conditions"
                        :key="index"
                        class="space-y-2 rounded-lg border p-3"
                    >
                        <div class="flex flex-wrap items-center gap-2">
                            <Select
                                :model-value="condition.field"
                                @update:model-value="
                                    (value) =>
                                        changeField(
                                            condition,
                                            index,
                                            value as CalendarFilterField,
                                        )
                                "
                            >
                                <SelectTrigger
                                    size="sm"
                                    class="w-40"
                                    aria-label="Field"
                                >
                                    <SelectValue />
                                </SelectTrigger>
                                <SelectContent>
                                    <SelectItem
                                        v-for="field in filterFields"
                                        :key="field.value"
                                        :value="field.value"
                                        >{{ field.label }}</SelectItem
                                    >
                                </SelectContent>
                            </Select>
                            <Select v-model="condition.operator">
                                <SelectTrigger
                                    size="sm"
                                    class="w-40"
                                    aria-label="Operator"
                                >
                                    <SelectValue />
                                </SelectTrigger>
                                <SelectContent>
                                    <SelectItem
                                        v-for="operator in filterFields.find(
                                            (f) => f.value === condition.field,
                                        )!.operators"
                                        :key="operator"
                                        :value="operator"
                                        >{{
                                            operatorLabels[condition.field][
                                                operator
                                            ]
                                        }}</SelectItem
                                    >
                                </SelectContent>
                            </Select>
                            <Button
                                type="button"
                                size="icon"
                                variant="ghost"
                                class="ml-auto size-8"
                                aria-label="Remove condition"
                                @click="removeCondition(index)"
                            >
                                <Trash2 />
                            </Button>
                        </div>

                        <Input
                            v-if="condition.field === 'name'"
                            :model-value="condition.values[0] ?? ''"
                            placeholder="Text in the session or patient name"
                            @update:model-value="
                                (value) =>
                                    (condition.values = String(value)
                                        ? [String(value)]
                                        : [])
                            "
                        />
                        <PatientPicker
                            v-else-if="condition.field === 'patient'"
                            :model-value="pickedPatients[index] ?? []"
                            placeholder="Choose patients"
                            @update:model-value="
                                (patients) =>
                                    setPatients(condition, index, patients)
                            "
                        />
                        <div v-else class="flex flex-wrap gap-1.5">
                            <button
                                v-for="option in valueOptions[condition.field]"
                                :key="option.value"
                                type="button"
                                :aria-pressed="
                                    condition.values.includes(option.value)
                                "
                                :class="
                                    cn(
                                        'rounded-full border px-3 py-1 text-xs font-medium transition-colors',
                                        condition.values.includes(option.value)
                                            ? 'border-teal-600 bg-teal-600 text-white'
                                            : 'bg-background text-muted-foreground hover:bg-accent',
                                    )
                                "
                                @click="toggleValue(condition, option.value)"
                            >
                                {{ option.label }}
                            </button>
                        </div>
                        <InputError :message="conditionError(index)" />
                    </li>
                </ol>

                <div>
                    <Button
                        type="button"
                        size="sm"
                        variant="outline"
                        :disabled="form.rules.conditions.length >= 20"
                        @click="addCondition"
                    >
                        <Plus />
                        Add condition
                    </Button>
                    <InputError
                        :message="
                            form.errors[
                                'rules.conditions' as keyof typeof form.errors
                            ] as string | undefined
                        "
                    />
                </div>
            </form>

            <DialogFooter class="sm:justify-between">
                <Button
                    v-if="filter"
                    type="button"
                    variant="ghost"
                    class="text-destructive"
                    @click="emit('delete', filter)"
                >
                    <X />
                    Delete filter
                </Button>
                <div class="flex gap-2 sm:ml-auto">
                    <Button variant="outline" @click="open = false"
                        >Cancel</Button
                    >
                    <Button
                        type="submit"
                        form="calendar-filter-form"
                        :disabled="form.processing"
                    >
                        <Spinner v-if="form.processing" />
                        {{ filter ? 'Save filter' : 'Save and apply' }}
                    </Button>
                </div>
            </DialogFooter>
        </DialogContent>
    </Dialog>
</template>
