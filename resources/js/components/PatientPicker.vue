<script setup lang="ts">
import { Check, Plus, Search, X } from '@lucide/vue';
import { refDebounced } from '@vueuse/core';
import { computed, ref } from 'vue';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import {
    Popover,
    PopoverContent,
    PopoverTrigger,
} from '@/components/ui/popover';
import { Skeleton } from '@/components/ui/skeleton';
import { useApiQuery } from '@/composables/useApiQuery';
import { useClinic } from '@/composables/useClinic';
import { patientName } from '@/lib/format';
import { index } from '@/routes/clinic/patients';
import type { Paginated, Patient } from '@/types';

type Props = {
    /** Patients that cannot be picked (e.g. already in the session). */
    excludeIds?: number[];
    placeholder?: string;
    /** Single mode emits `pick` and closes; multi mode edits v-model. */
    single?: boolean;
};

const props = withDefaults(defineProps<Props>(), {
    excludeIds: () => [],
    placeholder: 'Add patients',
    single: false,
});

const selected = defineModel<Patient[]>({ default: () => [] });
const emit = defineEmits<{ pick: [patient: Patient] }>();

const { clinicId } = useClinic();
const open = ref(false);
const search = ref('');
const debouncedSearch = refDebounced(search, 250);

const { data, loading } = useApiQuery<Paginated<Patient>>(() =>
    open.value
        ? index.url(clinicId.value, {
              query: {
                  search: debouncedSearch.value || undefined,
                  per_page: 20,
              },
          })
        : null,
);

const options = computed(() =>
    (data.value?.data ?? []).filter(
        (patient) => !props.excludeIds.includes(patient.id),
    ),
);
const isSelected = (patient: Patient) =>
    selected.value.some((p) => p.id === patient.id);

function toggle(patient: Patient): void {
    if (props.single) {
        emit('pick', patient);
        open.value = false;

        return;
    }

    selected.value = isSelected(patient)
        ? selected.value.filter((p) => p.id !== patient.id)
        : [...selected.value, patient];
}
</script>

<template>
    <div class="flex flex-col gap-2">
        <div v-if="!single && selected.length" class="flex flex-wrap gap-1.5">
            <Badge
                v-for="patient in selected"
                :key="patient.id"
                variant="secondary"
                class="gap-1 pr-1"
            >
                {{ patientName(patient) }}
                <button
                    type="button"
                    class="rounded-full p-0.5 hover:bg-background"
                    :aria-label="`Remove ${patientName(patient)}`"
                    @click="toggle(patient)"
                >
                    <X class="size-3" />
                </button>
            </Badge>
        </div>
        <Popover v-model:open="open">
            <PopoverTrigger as-child>
                <Button
                    type="button"
                    variant="outline"
                    class="justify-start text-muted-foreground"
                >
                    <Plus />
                    {{ placeholder }}
                </Button>
            </PopoverTrigger>
            <PopoverContent class="w-80 p-0" align="start">
                <div class="relative border-b">
                    <Search
                        class="pointer-events-none absolute top-2.5 left-3 size-4 text-muted-foreground"
                    />
                    <Input
                        v-model="search"
                        v-focus
                        placeholder="Search patients"
                        class="rounded-none border-0 pl-9 shadow-none focus-visible:ring-0"
                    />
                </div>
                <div class="max-h-64 overflow-y-auto p-1">
                    <template v-if="loading && !data">
                        <Skeleton v-for="row in 4" :key="row" class="m-1 h-8" />
                    </template>
                    <p
                        v-else-if="options.length === 0"
                        class="px-3 py-6 text-center text-sm text-muted-foreground"
                    >
                        No patients found.
                    </p>
                    <button
                        v-for="patient in options"
                        :key="patient.id"
                        type="button"
                        class="flex w-full items-center gap-2 rounded-sm px-2 py-1.5 text-left text-sm hover:bg-accent"
                        @click="toggle(patient)"
                    >
                        <Check
                            class="size-4"
                            :class="
                                isSelected(patient)
                                    ? 'opacity-100'
                                    : 'opacity-0'
                            "
                        />
                        <span class="flex-1 truncate">{{
                            patientName(patient)
                        }}</span>
                        <span class="text-xs text-muted-foreground">{{
                            patient.phone
                        }}</span>
                    </button>
                </div>
            </PopoverContent>
        </Popover>
    </div>
</template>
