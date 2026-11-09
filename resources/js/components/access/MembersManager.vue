<script setup lang="ts">
import { usePage } from '@inertiajs/vue3';
import { Shield, UserMinus, UserPlus } from '@lucide/vue';
import { onMounted, ref } from 'vue';
import { toast } from 'vue-sonner';
import ConfirmDialog from '@/components/ConfirmDialog.vue';
import DataPagination from '@/components/DataPagination.vue';
import EmptyState from '@/components/EmptyState.vue';
import { Avatar, AvatarFallback } from '@/components/ui/avatar';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card } from '@/components/ui/card';
import { Skeleton } from '@/components/ui/skeleton';
import {
    Table,
    TableBody,
    TableCell,
    TableHead,
    TableHeader,
    TableRow,
} from '@/components/ui/table';
import { useApiQuery } from '@/composables/useApiQuery';
import { usePagination } from '@/composables/usePagination';
import { useInitials } from '@/composables/useInitials';
import { api, ApiError } from '@/lib/api';
import { formatDate } from '@/lib/format';
import type { ClinicMember, ClinicRole, Paginated, Wrapped } from '@/types';
import MemberFormDialog from './MemberFormDialog.vue';
import type { MemberEndpoints } from './types';

type Props = {
    endpoints: MemberEndpoints;
    canManage: boolean;
};

const props = defineProps<Props>();
const { getInitials } = useInitials();
const currentUserId = usePage().props.auth.user?.id;

const { page, perPage } = usePagination();
const { data, loading, refresh } = useApiQuery<Paginated<ClinicMember>>(() =>
    props.endpoints.list(page.value, perPage.value),
);
const roles = ref<ClinicRole[]>([]);

const formOpen = ref(false);
const editing = ref<ClinicMember | null>(null);
const removing = ref<ClinicMember | null>(null);
const removeOpen = ref(false);
const removeProcessing = ref(false);

onMounted(async () => {
    if (props.canManage) {
        roles.value = (
            await api.get<Wrapped<ClinicRole[]>>(props.endpoints.roles)
        ).data;
    }
});

function openForm(member: ClinicMember | null): void {
    editing.value = member;
    formOpen.value = true;
}

async function remove(): Promise<void> {
    if (!removing.value) {
        return;
    }

    removeProcessing.value = true;

    try {
        await api.delete(props.endpoints.member(removing.value.id));
        toast.success(`${removing.value.user?.name} removed.`);
        removeOpen.value = false;
        void refresh();
    } catch (error) {
        toast.error(
            error instanceof ApiError
                ? error.firstMessage
                : 'Could not remove the member.',
        );
    } finally {
        removeProcessing.value = false;
    }
}

defineExpose({ openCreate: () => openForm(null) });
</script>

<template>
    <div class="flex flex-col gap-4">
        <Card
            class="animate__animated animate__fadeInUp overflow-hidden py-0"
            style="--animate-duration: 0.5s"
        >
            <Table>
                <TableHeader>
                    <TableRow>
                        <TableHead>Member</TableHead>
                        <TableHead>Roles</TableHead>
                        <TableHead class="hidden md:table-cell"
                            >Joined</TableHead
                        >
                        <TableHead v-if="canManage" class="w-24" />
                    </TableRow>
                </TableHeader>
                <TableBody>
                    <template v-if="loading && !data">
                        <TableRow v-for="row in 3" :key="row">
                            <TableCell colspan="4"
                                ><Skeleton class="h-8 w-full"
                            /></TableCell>
                        </TableRow>
                    </template>
                    <TableRow
                        v-for="member in data?.data ?? []"
                        :key="member.id"
                        class="group"
                    >
                        <TableCell>
                            <div class="flex items-center gap-3">
                                <Avatar class="size-8">
                                    <AvatarFallback class="text-xs">{{
                                        getInitials(member.user?.name ?? '')
                                    }}</AvatarFallback>
                                </Avatar>
                                <div>
                                    <div class="font-medium">
                                        {{ member.user?.name }}
                                        <span
                                            v-if="
                                                member.user?.id ===
                                                currentUserId
                                            "
                                            class="text-xs text-muted-foreground"
                                            >(you)</span
                                        >
                                    </div>
                                    <div class="text-sm text-muted-foreground">
                                        {{ member.user?.email }}
                                    </div>
                                </div>
                            </div>
                        </TableCell>
                        <TableCell>
                            <div class="flex flex-wrap gap-1">
                                <Badge
                                    v-for="role in member.roles"
                                    :key="role.id"
                                    :variant="
                                        role.key === 'admin'
                                            ? 'default'
                                            : 'secondary'
                                    "
                                >
                                    {{ role.name }}
                                </Badge>
                                <span
                                    v-if="!member.roles?.length"
                                    class="text-sm text-muted-foreground"
                                    >No roles</span
                                >
                            </div>
                        </TableCell>
                        <TableCell
                            class="hidden text-muted-foreground md:table-cell"
                            >{{ formatDate(member.joined_at) }}</TableCell
                        >
                        <TableCell v-if="canManage">
                            <div
                                class="flex justify-end gap-1 opacity-70 transition-opacity group-hover:opacity-100"
                            >
                                <Button
                                    variant="ghost"
                                    size="icon"
                                    aria-label="Change roles"
                                    @click="openForm(member)"
                                >
                                    <Shield />
                                </Button>
                                <Button
                                    variant="ghost"
                                    size="icon"
                                    aria-label="Remove member"
                                    @click="
                                        removing = member;
                                        removeOpen = true;
                                    "
                                >
                                    <UserMinus />
                                </Button>
                            </div>
                        </TableCell>
                    </TableRow>
                </TableBody>
            </Table>
            <EmptyState
                v-if="data && data.data.length === 0"
                title="No staff yet"
            >
                <Button v-if="canManage" @click="openForm(null)">
                    <UserPlus />
                    Add a staff member
                </Button>
            </EmptyState>
        </Card>

        <DataPagination
            v-if="data"
            v-model:page="page"
            v-model:per-page="perPage"
            :meta="data.meta"
        />
    </div>

    <MemberFormDialog
        v-if="canManage"
        v-model:open="formOpen"
        :endpoints="endpoints"
        :roles="roles"
        :member="editing"
        @saved="
            (member) => {
                toast.success(
                    editing
                        ? `Roles updated for ${member.user?.name}.`
                        : `${member.user?.name} added.`,
                );
                refresh();
            }
        "
    />
    <ConfirmDialog
        v-model:open="removeOpen"
        :title="`Remove ${removing?.user?.name}?`"
        description="They lose access to this clinic. Their past sessions and recorded payments keep their name."
        confirm-label="Remove"
        :processing="removeProcessing"
        @confirm="remove"
    />
</template>
