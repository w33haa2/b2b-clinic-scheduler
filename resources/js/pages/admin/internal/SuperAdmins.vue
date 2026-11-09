<script setup lang="ts">
import { Head, setLayoutProps, usePage, useHttp } from '@inertiajs/vue3';
import { Pencil, Plus, ShieldCheck, Trash2 } from '@lucide/vue';
import { ref } from 'vue';
import { toast } from 'vue-sonner';
import ConfirmDialog from '@/components/ConfirmDialog.vue';
import DataPagination from '@/components/DataPagination.vue';
import FormField from '@/components/FormField.vue';
import PageHeader from '@/components/PageHeader.vue';
import { Button } from '@/components/ui/button';
import { Card } from '@/components/ui/card';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { Skeleton } from '@/components/ui/skeleton';
import { Spinner } from '@/components/ui/spinner';
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
import { api, ApiError } from '@/lib/api';
import { formatDate } from '@/lib/format';
import { superAdmins as superAdminsPage } from '@/routes/admin/internal';
import * as superAdmins from '@/routes/internal/super-admins';
import type { ApiUser, Paginated, Wrapped } from '@/types';

setLayoutProps({
    breadcrumbs: [{ title: 'Super admins', href: superAdminsPage() }],
});

const currentUserId = usePage().props.auth.user?.id;
const { page, perPage, query } = usePagination();
const { data, loading, refresh } = useApiQuery<Paginated<ApiUser>>(() =>
    superAdmins.index.url({ query: query.value }),
);

const formOpen = ref(false);
const editing = ref<ApiUser | null>(null);
const form = useHttp<
    { name: string; email: string; password: string },
    Wrapped<ApiUser>
>({ name: '', email: '', password: '' });
form.transform(
    (values) =>
        (editing.value && !values.password
            ? { name: values.name, email: values.email }
            : values) as typeof values,
);

function openForm(user: ApiUser | null): void {
    editing.value = user;
    form.defaults({
        name: user?.name ?? '',
        email: user?.email ?? '',
        password: '',
    })
        .reset()
        .clearErrors();
    formOpen.value = true;
}

async function save(): Promise<void> {
    const options = {
        onSuccess: (response: Wrapped<ApiUser>) => {
            toast.success(`${response.data.name} saved.`);
            formOpen.value = false;
            void refresh();
        },
    };

    if (editing.value) {
        await form.patch(superAdmins.update.url(editing.value.id), options);
    } else {
        await form.post(superAdmins.store.url(), options);
    }
}

const deleting = ref<ApiUser | null>(null);
const deleteOpen = ref(false);
const deleteProcessing = ref(false);

async function remove(): Promise<void> {
    if (!deleting.value) {
        return;
    }

    deleteProcessing.value = true;

    try {
        await api.delete(superAdmins.destroy.url(deleting.value.id));
        toast.success(`${deleting.value.name} deleted.`);
        deleteOpen.value = false;
        void refresh();
    } catch (error) {
        toast.error(
            error instanceof ApiError
                ? error.firstMessage
                : 'Could not delete the super admin.',
        );
    } finally {
        deleteProcessing.value = false;
    }
}
</script>

<template>
    <Head title="Super admins" />

    <div class="flex flex-1 flex-col gap-6 p-4 md:p-6">
        <PageHeader
            title="Super admins"
            description="People who can use this internal tool. They are never members of a clinic."
        >
            <template #actions>
                <Button @click="openForm(null)">
                    <Plus />
                    New super admin
                </Button>
            </template>
        </PageHeader>

        <Card class="overflow-hidden py-0">
            <Table>
                <TableHeader>
                    <TableRow>
                        <TableHead>Name</TableHead>
                        <TableHead>Email</TableHead>
                        <TableHead class="hidden md:table-cell"
                            >Since</TableHead
                        >
                        <TableHead class="w-24" />
                    </TableRow>
                </TableHeader>
                <TableBody>
                    <template v-if="loading && !data">
                        <TableRow v-for="row in 2" :key="row">
                            <TableCell colspan="4"
                                ><Skeleton class="h-6 w-full"
                            /></TableCell>
                        </TableRow>
                    </template>
                    <TableRow v-for="user in data?.data ?? []" :key="user.id">
                        <TableCell class="font-medium">
                            <span class="flex items-center gap-2">
                                <ShieldCheck class="size-4 text-teal-600" />
                                {{ user.name }}
                                <span
                                    v-if="user.id === currentUserId"
                                    class="text-xs text-muted-foreground"
                                    >(you)</span
                                >
                            </span>
                        </TableCell>
                        <TableCell class="text-muted-foreground">{{
                            user.email
                        }}</TableCell>
                        <TableCell
                            class="hidden text-muted-foreground md:table-cell"
                            >{{ formatDate(user.created_at) }}</TableCell
                        >
                        <TableCell>
                            <div class="flex justify-end gap-1">
                                <Button
                                    variant="ghost"
                                    size="icon"
                                    aria-label="Edit"
                                    @click="openForm(user)"
                                    ><Pencil
                                /></Button>
                                <Button
                                    v-if="user.id !== currentUserId"
                                    variant="ghost"
                                    size="icon"
                                    aria-label="Delete"
                                    @click="
                                        deleting = user;
                                        deleteOpen = true;
                                    "
                                >
                                    <Trash2 />
                                </Button>
                            </div>
                        </TableCell>
                    </TableRow>
                </TableBody>
            </Table>
        </Card>

        <DataPagination
            v-if="data"
            v-model:page="page"
            v-model:per-page="perPage"
            :meta="data.meta"
        />
    </div>

    <Dialog v-model:open="formOpen">
        <DialogContent class="sm:max-w-md">
            <DialogHeader>
                <DialogTitle>{{
                    editing ? `Edit ${editing.name}` : 'New super admin'
                }}</DialogTitle>
                <DialogDescription v-if="editing"
                    >Leave the password empty to keep it.</DialogDescription
                >
            </DialogHeader>
            <form
                id="super-admin-form"
                class="grid gap-4"
                @submit.prevent="save"
            >
                <FormField label="Name" for="sa-name" :error="form.errors.name">
                    <Input id="sa-name" v-model="form.name" v-focus required />
                </FormField>
                <FormField
                    label="Email"
                    for="sa-email"
                    :error="form.errors.email"
                >
                    <Input
                        id="sa-email"
                        v-model="form.email"
                        type="email"
                        required
                    />
                </FormField>
                <FormField
                    label="Password"
                    for="sa-password"
                    :error="form.errors.password"
                >
                    <Input
                        id="sa-password"
                        v-model="form.password"
                        type="password"
                        autocomplete="new-password"
                        :required="!editing"
                    />
                </FormField>
            </form>
            <DialogFooter>
                <Button variant="outline" @click="formOpen = false"
                    >Cancel</Button
                >
                <Button
                    type="submit"
                    form="super-admin-form"
                    :disabled="form.processing"
                >
                    <Spinner v-if="form.processing" />
                    Save
                </Button>
            </DialogFooter>
        </DialogContent>
    </Dialog>

    <ConfirmDialog
        v-model:open="deleteOpen"
        :title="`Delete ${deleting?.name}?`"
        description="They lose access to the internal tool and their account is deleted."
        :processing="deleteProcessing"
        @confirm="remove"
    />
</template>
