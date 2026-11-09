<script setup lang="ts">
import { Check } from '@lucide/vue';
import MockFrame from './MockFrame.vue';

const groups = [
    {
        name: 'Sessions',
        items: [
            ['View list', true],
            ['Create', true],
            ['Change status', true],
            ['Delete', false],
        ],
    },
    { name: 'Payments', items: [['Record payments and refunds', true]] },
    {
        name: 'Invoices',
        items: [
            ['View list', true],
            ['Create', true],
            ['Delete', false],
        ],
    },
    {
        name: 'Services & pricing',
        items: [
            ['View list', true],
            ['Edit', false],
        ],
    },
] as const;
</script>

<template>
    <MockFrame
        title="Roles · Front desk"
        label="Editing the Front desk role: individual permissions for sessions, payments, invoices and pricing."
    >
        <div class="grid gap-2.5 p-3 text-xs sm:grid-cols-2">
            <div
                v-for="group in groups"
                :key="group.name"
                class="rounded-md border bg-card p-3"
            >
                <div class="mb-2 font-semibold">{{ group.name }}</div>
                <div
                    v-for="[label, allowed] in group.items"
                    :key="label"
                    class="flex items-center gap-2 py-0.5"
                >
                    <span
                        class="flex size-3.5 items-center justify-center rounded-[4px] border"
                        :class="
                            allowed &&
                            'border-primary bg-primary text-primary-foreground'
                        "
                    >
                        <Check v-if="allowed" class="size-2.5" />
                    </span>
                    <span :class="!allowed && 'text-muted-foreground'">{{
                        label
                    }}</span>
                </div>
            </div>
        </div>
    </MockFrame>
</template>
