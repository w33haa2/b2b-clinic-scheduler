<script setup lang="ts">
import { Head, Link, router, setLayoutProps, useHttp } from '@inertiajs/vue3';
import { Building2, ChevronRight, Plus, Search } from '@lucide/vue';
import { refDebounced } from '@vueuse/core';
import { computed, ref, watch } from 'vue';
import { toast } from 'vue-sonner';
import DataPagination from '@/components/DataPagination.vue';
import EmptyState from '@/components/EmptyState.vue';
import FormField from '@/components/FormField.vue';
import TimezoneCombobox from '@/components/form/TimezoneCombobox.vue';
import PageHeader from '@/components/PageHeader.vue';
import { Button } from '@/components/ui/button';
import { Card } from '@/components/ui/card';
import { Checkbox } from '@/components/ui/checkbox';
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
import { formatDate } from '@/lib/format';
import {
    index as clinicsPage,
    show as clinicPage,
} from '@/routes/admin/internal/clinics';
import { index, store } from '@/routes/internal/clinics';
import type { Clinic, Paginated, Wrapped } from '@/types';

setLayoutProps({ breadcrumbs: [{ title: 'Clinics', href: clinicsPage() }] });

const search = ref('');
const debouncedSearch = refDebounced(search, 300);
const { page, perPage, query } = usePagination(25, [debouncedSearch]);

const { data, loading } = useApiQuery<Paginated<Clinic>>(() =>
    index.url({
        query: { search: debouncedSearch.value || undefined, ...query.value },
    }),
);

const createOpen = ref(false);
const withAdmin = ref(true);
const form = useHttp<
    {
        name: string;
        address: string;
        timezone: string;
        admin: { name: string; email: string; password: string };
    },
    Wrapped<Clinic>
>({
    name: '',
    address: '',
    timezone: Intl.DateTimeFormat().resolvedOptions().timeZone,
    admin: { name: '', email: '', password: '' },
});

form.transform((values) => {
    const payload: Record<string, unknown> = {
        name: values.name,
        address: values.address || null,
        timezone: values.timezone,
    };

    if (withAdmin.value) {
        payload.admin = {
            ...values.admin,
            password: values.admin.password || null,
        };
    }

    return payload as typeof values;
});

function openCreate(): void {
    form.reset();
    form.clearErrors();
    withAdmin.value = true;
    createOpen.value = true;
}

async function create(): Promise<void> {
    await form.post(store.url(), {
        onSuccess: (response: Wrapped<Clinic>) => {
            toast.success(`${response.data.name} created.`);
            createOpen.value = false;
            router.visit(clinicPage(response.data.id));
        },
    });
}
</script>

<template>
    <Head title="Clinics" />

    <div class="flex flex-1 flex-col gap-6 p-4 md:p-6">
        <PageHeader title="Clinics" description="Every clinic on the platform.">
            <template #actions>
                <div class="relative">
                    <Search
                        class="pointer-events-none absolute top-2.5 left-2.5 size-4 text-muted-foreground"
                    />
                    <Input
                        v-model="search"
                        placeholder="Search clinics"
                        class="w-56 pl-8"
                    />
                </div>
                <Button @click="openCreate">
                    <Plus />
                    New clinic
                </Button>
            </template>
        </PageHeader>

        <Card class="overflow-hidden py-0">
            <Table>
                <TableHeader>
                    <TableRow>
                        <TableHead>Clinic</TableHead>
                        <TableHead class="hidden md:table-cell"
                            >Timezone</TableHead
                        >
                        <TableHead class="hidden md:table-cell"
                            >Created</TableHead
                        >
                        <TableHead class="w-10" />
                    </TableRow>
                </TableHeader>
                <TableBody>
                    <template v-if="loading && !data">
                        <TableRow v-for="row in 4" :key="row">
                            <TableCell colspan="4"
                                ><Skeleton class="h-6 w-full"
                            /></TableCell>
                        </TableRow>
                    </template>
                    <TableRow
                        v-for="clinic in data?.data ?? []"
                        :key="clinic.id"
                        class="cursor-pointer"
                        @click="router.visit(clinicPage(clinic.id))"
                    >
                        <TableCell>
                            <Link
                                :href="clinicPage(clinic.id)"
                                class="flex items-center gap-3"
                                @click.stop
                            >
                                <div
                                    class="flex size-8 items-center justify-center rounded-md bg-muted"
                                >
                                    <Building2 class="size-4" />
                                </div>
                                <div>
                                    <div class="font-medium">
                                        {{ clinic.name }}
                                    </div>
                                    <div
                                        class="max-w-md truncate text-sm text-muted-foreground"
                                    >
                                        {{ clinic.address }}
                                    </div>
                                </div>
                            </Link>
                        </TableCell>
                        <TableCell
                            class="hidden text-muted-foreground md:table-cell"
                            >{{ clinic.timezone }}</TableCell
                        >
                        <TableCell
                            class="hidden text-muted-foreground md:table-cell"
                            >{{ formatDate(clinic.created_at) }}</TableCell
                        >
                        <TableCell
                            ><ChevronRight class="size-4 text-muted-foreground"
                        /></TableCell>
                    </TableRow>
                </TableBody>
            </Table>
            <EmptyState
                v-if="data && data.data.length === 0"
                title="No clinics found"
            />
        </Card>

        <DataPagination
            v-if="data"
            v-model:page="page"
            v-model:per-page="perPage"
            :meta="data.meta"
        />
    </div>

    <Dialog v-model:open="createOpen">
        <DialogContent class="sm:max-w-lg">
            <DialogHeader>
                <DialogTitle>New clinic</DialogTitle>
                <DialogDescription
                    >The Clinic Admin and Clinic Worker roles are created
                    automatically.</DialogDescription
                >
            </DialogHeader>
            <form id="clinic-form" class="grid gap-4" @submit.prevent="create">
                <FormField
                    label="Name"
                    for="clinic-name"
                    :error="form.errors.name"
                >
                    <Input
                        id="clinic-name"
                        v-model="form.name"
                        v-focus
                        required
                    />
                </FormField>
                <FormField
                    label="Address"
                    for="clinic-address"
                    :error="form.errors.address"
                >
                    <Input id="clinic-address" v-model="form.address" />
                </FormField>
                <FormField
                    label="Timezone"
                    for="clinic-timezone"
                    :error="form.errors.timezone"
                >
                    <TimezoneCombobox
                        id="clinic-timezone"
                        v-model="form.timezone"
                    />
                </FormField>

                <label class="flex items-center gap-2 text-sm font-medium">
                    <Checkbox v-model="withAdmin" />
                    Also create the first Clinic Admin
                </label>
                <div
                    v-if="withAdmin"
                    class="grid gap-4 rounded-lg border bg-muted/30 p-3 sm:grid-cols-2"
                >
                    <FormField
                        label="Admin name"
                        for="admin-name"
                        :error="form.errors['admin.name']"
                    >
                        <Input
                            id="admin-name"
                            v-model="form.admin.name"
                            required
                        />
                    </FormField>
                    <FormField
                        label="Admin email"
                        for="admin-email"
                        :error="form.errors['admin.email']"
                    >
                        <Input
                            id="admin-email"
                            v-model="form.admin.email"
                            type="email"
                            required
                        />
                    </FormField>
                    <FormField
                        label="Password (optional)"
                        for="admin-password"
                        :error="form.errors['admin.password']"
                        hint="Leave empty to have them use “Forgot password”."
                        class="sm:col-span-2"
                    >
                        <Input
                            id="admin-password"
                            v-model="form.admin.password"
                            type="password"
                            autocomplete="new-password"
                        />
                    </FormField>
                </div>
            </form>
            <DialogFooter>
                <Button variant="outline" @click="createOpen = false"
                    >Cancel</Button
                >
                <Button
                    type="submit"
                    form="clinic-form"
                    :disabled="form.processing"
                >
                    <Spinner v-if="form.processing" />
                    Create clinic
                </Button>
            </DialogFooter>
        </DialogContent>
    </Dialog>
</template>
