<script setup lang="ts">
import { Crown, Lock, Pencil, Trash2 } from '@lucide/vue';
import { onMounted, ref } from 'vue';
import { toast } from 'vue-sonner';
import ConfirmDialog from '@/components/ConfirmDialog.vue';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import {
    Card,
    CardContent,
    CardDescription,
    CardHeader,
    CardTitle,
} from '@/components/ui/card';
import { Skeleton } from '@/components/ui/skeleton';
import { api, ApiError } from '@/lib/api';
import { groupPermissions } from '@/lib/permissions';
import type { ClinicRole, Permission, Wrapped } from '@/types';
import RoleFormDialog from './RoleFormDialog.vue';
import type { RoleEndpoints } from './types';

type Props = {
    endpoints: RoleEndpoints;
    canManage: boolean;
};

const props = defineProps<Props>();

const roles = ref<ClinicRole[] | null>(null);
const permissions = ref<Permission[]>([]);
const formOpen = ref(false);
const editing = ref<ClinicRole | null>(null);
const deleting = ref<ClinicRole | null>(null);
const deleteOpen = ref(false);
const deleteProcessing = ref(false);

async function load(): Promise<void> {
    roles.value = (
        await api.get<Wrapped<ClinicRole[]>>(props.endpoints.list)
    ).data;
}

onMounted(async () => {
    await Promise.all([
        load(),
        // Only managers need the full list (members may lack the permission to read it).
        props.canManage
            ? api
                  .get<Wrapped<Permission[]>>(props.endpoints.permissions)
                  .then((response) => (permissions.value = response.data))
            : null,
    ]);
});

function openForm(role: ClinicRole | null): void {
    editing.value = role;
    formOpen.value = true;
}

async function remove(): Promise<void> {
    if (!deleting.value) {
        return;
    }

    deleteProcessing.value = true;

    try {
        await api.delete(props.endpoints.role(deleting.value.id));
        toast.success(`${deleting.value.name} deleted.`);
        deleteOpen.value = false;
        await load();
    } catch (error) {
        toast.error(
            error instanceof ApiError
                ? error.firstMessage
                : 'Could not delete the role.',
        );
    } finally {
        deleteProcessing.value = false;
    }
}

const summary = (role: ClinicRole) =>
    groupPermissions(
        (role.permissions ?? []).map((name, id) => ({ id, name })),
    );

defineExpose({ openCreate: () => openForm(null) });
</script>

<template>
    <div v-if="!roles" class="grid gap-4 md:grid-cols-2 xl:grid-cols-3">
        <Skeleton v-for="card in 3" :key="card" class="h-48 rounded-xl" />
    </div>
    <div v-else class="grid gap-4 md:grid-cols-2 xl:grid-cols-3">
        <Card
            v-for="(role, position) in roles"
            :key="role.id"
            class="animate__animated animate__fadeInUp gap-4"
            :style="{
                animationDelay: `${position * 70}ms`,
                '--animate-duration': '0.45s',
            }"
        >
            <CardHeader>
                <div class="flex items-start justify-between gap-2">
                    <div class="space-y-1">
                        <CardTitle class="flex items-center gap-2">
                            <Crown
                                v-if="role.key === 'admin'"
                                class="size-4 text-amber-500"
                            />
                            {{ role.name }}
                        </CardTitle>
                        <CardDescription>
                            <template v-if="role.key === 'admin'"
                                >Every permission, including staff and
                                roles.</template
                            >
                            <template v-else
                                >{{
                                    role.permissions?.length ?? 0
                                }}
                                permissions</template
                            >
                        </CardDescription>
                    </div>
                    <Badge
                        v-if="role.is_system"
                        variant="secondary"
                        class="gap-1"
                        ><Lock /> Built-in</Badge
                    >
                </div>
            </CardHeader>
            <CardContent class="flex flex-1 flex-col gap-4">
                <div v-if="role.key !== 'admin'" class="flex flex-wrap gap-1">
                    <Badge
                        v-for="group in summary(role)"
                        :key="group.group"
                        variant="outline"
                    >
                        {{ group.group }} · {{ group.items.length }}
                    </Badge>
                    <span
                        v-if="!role.permissions?.length"
                        class="text-sm text-muted-foreground"
                        >No permissions yet.</span
                    >
                </div>
                <div
                    v-if="canManage && role.key !== 'admin'"
                    class="mt-auto flex gap-2"
                >
                    <Button size="sm" variant="outline" @click="openForm(role)">
                        <Pencil />
                        Edit
                    </Button>
                    <Button
                        v-if="!role.is_system"
                        size="sm"
                        variant="ghost"
                        class="text-destructive"
                        @click="
                            deleting = role;
                            deleteOpen = true;
                        "
                    >
                        <Trash2 />
                        Delete
                    </Button>
                </div>
            </CardContent>
        </Card>
    </div>

    <RoleFormDialog
        v-if="canManage"
        v-model:open="formOpen"
        :endpoints="endpoints"
        :permissions="permissions"
        :role="editing"
        @saved="
            (role) => {
                toast.success(`${role.name} saved.`);
                load();
            }
        "
    />
    <ConfirmDialog
        v-model:open="deleteOpen"
        :title="`Delete ${deleting?.name}?`"
        description="Members who hold this role lose its permissions."
        :processing="deleteProcessing"
        @confirm="remove"
    />
</template>
