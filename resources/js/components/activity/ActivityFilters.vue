<script setup lang="ts">
import { Search, X } from '@lucide/vue';
import { computed } from 'vue';
import DatePicker from '@/components/form/DatePicker.vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import { Tabs, TabsList, TabsTrigger } from '@/components/ui/tabs';
import type { ActivityFilterOption } from '@/types';

/**
 * Filters for an activity log. "all" is the Select value for "no filter"
 * (reka Select items cannot have an empty value).
 */
export type ActivityFilterState = {
    level: 'all' | 'platform' | 'clinic';
    clinic_id: string;
    category: string;
    user_id: string;
    from: string;
    to: string;
    search: string;
};

type Props = {
    categories: ActivityFilterOption[];
    actors?: Array<{ id: number; name: string; is_super_admin: boolean }>;
    clinics?: Array<{ id: number; name: string }>;
    /** Internal tool: platform vs clinic entries, and a clinic picker. */
    showLevel?: boolean;
    hideSupportNames?: boolean;
};

const props = withDefaults(defineProps<Props>(), {
    showLevel: false,
    hideSupportNames: false,
});
const filters = defineModel<ActivityFilterState>({ required: true });

const isFiltered = computed(() =>
    Object.entries(filters.value).some(([key, value]) =>
        key === 'level' || key.endsWith('_id') || key === 'category'
            ? value !== 'all'
            : value !== '',
    ),
);

function reset(): void {
    filters.value = {
        level: 'all',
        clinic_id: 'all',
        category: 'all',
        user_id: 'all',
        from: '',
        to: '',
        search: '',
    };
}

const actorName = (actor: { name: string; is_super_admin: boolean }) =>
    props.hideSupportNames && actor.is_super_admin
        ? 'Clinic App support'
        : actor.name;
</script>

<template>
    <div class="flex flex-col gap-3">
        <Tabs v-if="showLevel" v-model="filters.level">
            <TabsList>
                <TabsTrigger value="all">Everything</TabsTrigger>
                <TabsTrigger value="platform">Platform</TabsTrigger>
                <TabsTrigger value="clinic">Clinics</TabsTrigger>
            </TabsList>
        </Tabs>

        <div class="flex flex-wrap items-center gap-2">
            <div class="relative min-w-56 flex-1">
                <Search
                    class="pointer-events-none absolute top-2.5 left-2.5 size-4 text-muted-foreground"
                />
                <Input
                    v-model="filters.search"
                    placeholder="Search person, record or action"
                    class="pl-8"
                />
            </div>

            <Select
                v-if="
                    showLevel && clinics?.length && filters.level !== 'platform'
                "
                v-model="filters.clinic_id"
            >
                <SelectTrigger class="w-48" aria-label="Clinic">
                    <SelectValue placeholder="All clinics" />
                </SelectTrigger>
                <SelectContent class="max-h-72">
                    <SelectItem value="all">All clinics</SelectItem>
                    <SelectItem
                        v-for="clinic in clinics"
                        :key="clinic.id"
                        :value="String(clinic.id)"
                        >{{ clinic.name }}</SelectItem
                    >
                </SelectContent>
            </Select>

            <Select v-model="filters.category">
                <SelectTrigger class="w-48" aria-label="Category">
                    <SelectValue placeholder="All activity" />
                </SelectTrigger>
                <SelectContent>
                    <SelectItem value="all">All activity</SelectItem>
                    <SelectItem
                        v-for="category in categories"
                        :key="category.value"
                        :value="category.value"
                    >
                        {{ category.label }}
                    </SelectItem>
                </SelectContent>
            </Select>

            <Select v-if="actors?.length" v-model="filters.user_id">
                <SelectTrigger class="w-44" aria-label="Person">
                    <SelectValue placeholder="Everyone" />
                </SelectTrigger>
                <SelectContent class="max-h-72">
                    <SelectItem value="all">Everyone</SelectItem>
                    <SelectItem
                        v-for="actor in actors"
                        :key="actor.id"
                        :value="String(actor.id)"
                        >{{ actorName(actor) }}</SelectItem
                    >
                </SelectContent>
            </Select>

            <DatePicker
                v-model="filters.from"
                placeholder="From"
                clearable
                max="today"
                class="w-40"
            />
            <DatePicker
                v-model="filters.to"
                placeholder="To"
                clearable
                max="today"
                class="w-40"
            />

            <Button v-if="isFiltered" variant="ghost" size="sm" @click="reset">
                <X />
                Clear filters
            </Button>
        </div>
    </div>
</template>
