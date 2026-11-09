<script setup lang="ts">
import { Search } from '@lucide/vue';
import MockFrame from './MockFrame.vue';
import { initials } from './sample';

const patients = [
    {
        name: 'Ana Reyes',
        meta: '34 · Female',
        phone: '0917 204 5531',
        last: 'Tue 4 Nov',
    },
    {
        name: 'Marco Santos',
        meta: '52 · Male',
        phone: '0918 772 0194',
        last: 'Mon 3 Nov',
    },
    {
        name: 'Ruth Adeyemi',
        meta: '41 · Female',
        phone: '0927 455 8810',
        last: 'Thu 30 Oct',
    },
    {
        name: 'Noah Lee',
        meta: '7 · Male',
        phone: '0915 330 2876',
        last: 'Tue 4 Nov',
    },
    {
        name: 'Ella Brooks',
        meta: '29 · Female',
        phone: '0906 118 4402',
        last: 'Mon 3 Nov',
    },
];
</script>

<template>
    <MockFrame
        title="Patients"
        label="The patient list with search, contact details and last visit."
    >
        <div class="p-3 text-xs">
            <div
                class="mb-3 flex h-8 items-center gap-2 rounded-md border bg-card px-2.5 text-muted-foreground"
            >
                <Search class="size-3.5" />
                <span>Search name, email, phone</span>
            </div>
            <div class="divide-y rounded-md border">
                <div
                    class="grid grid-cols-[1.6fr_1fr_1.2fr_1fr] px-3 py-2 font-medium text-muted-foreground"
                >
                    <span>Patient</span><span>Age / sex</span><span>Phone</span
                    ><span>Last visit</span>
                </div>
                <div
                    v-for="patient in patients"
                    :key="patient.name"
                    class="grid grid-cols-[1.6fr_1fr_1.2fr_1fr] items-center px-3 py-2"
                >
                    <span class="flex items-center gap-2 font-medium">
                        <span
                            class="flex size-6 items-center justify-center rounded-full bg-accent text-[9px] text-accent-foreground"
                        >
                            {{ initials(patient.name) }}
                        </span>
                        {{ patient.name }}
                    </span>
                    <span class="text-muted-foreground">{{
                        patient.meta
                    }}</span>
                    <span
                        class="text-[11px] text-muted-foreground tabular-nums"
                        >{{ patient.phone }}</span
                    >
                    <span class="text-muted-foreground">{{
                        patient.last
                    }}</span>
                </div>
            </div>
        </div>
    </MockFrame>
</template>
