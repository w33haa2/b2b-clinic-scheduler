<script setup lang="ts">
import { computed } from 'vue';
import { Checkbox } from '@/components/ui/checkbox';
import { groupPermissions } from '@/lib/permissions';
import type { Permission } from '@/types';

type Props = {
    permissions: Permission[];
    disabled?: boolean;
};

const props = defineProps<Props>();
const selected = defineModel<number[]>({ required: true });
const groups = computed(() => groupPermissions(props.permissions));

function toggle(id: number, checked: boolean | 'indeterminate'): void {
    selected.value =
        checked === true
            ? [...selected.value, id]
            : selected.value.filter((existing) => existing !== id);
}

function toggleGroup(ids: number[], checked: boolean | 'indeterminate'): void {
    const withoutGroup = selected.value.filter((id) => !ids.includes(id));
    selected.value =
        checked === true ? [...withoutGroup, ...ids] : withoutGroup;
}

function groupState(ids: number[]): boolean | 'indeterminate' {
    const count = ids.filter((id) => selected.value.includes(id)).length;

    return count === 0 ? false : count === ids.length ? true : 'indeterminate';
}
</script>

<template>
    <div class="grid gap-3 sm:grid-cols-2">
        <fieldset
            v-for="group in groups"
            :key="group.group"
            class="rounded-lg border p-3"
            :disabled="disabled"
        >
            <legend class="sr-only">{{ group.group }}</legend>
            <label
                class="mb-2 flex cursor-pointer items-center gap-2 text-sm font-semibold"
            >
                <Checkbox
                    :model-value="
                        groupState(group.items.map((item) => item.id))
                    "
                    :disabled="disabled"
                    @update:model-value="
                        (checked) =>
                            toggleGroup(
                                group.items.map((item) => item.id),
                                checked,
                            )
                    "
                />
                {{ group.group }}
            </label>
            <div class="grid gap-1.5 pl-6">
                <label
                    v-for="permission in group.items"
                    :key="permission.id"
                    class="flex cursor-pointer items-center gap-2 text-sm"
                    :title="permission.name"
                >
                    <Checkbox
                        :model-value="selected.includes(permission.id)"
                        :disabled="disabled"
                        @update:model-value="
                            (checked) => toggle(permission.id, checked)
                        "
                    />
                    <span class="text-muted-foreground">{{
                        permission.label
                    }}</span>
                </label>
            </div>
        </fieldset>
    </div>
</template>
