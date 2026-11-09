<script setup lang="ts">
import { Head, setLayoutProps } from '@inertiajs/vue3';
import { Archive, MoreHorizontal, Pencil, Plus, Search } from '@lucide/vue';
import { refDebounced } from '@vueuse/core';
import { ref } from 'vue';
import { toast } from 'vue-sonner';
import DiscountFormDialog from '@/components/clinic/DiscountFormDialog.vue';
import ConfirmDialog from '@/components/ConfirmDialog.vue';
import DataPagination from '@/components/DataPagination.vue';
import EmptyState from '@/components/EmptyState.vue';
import PageHeader from '@/components/PageHeader.vue';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card } from '@/components/ui/card';
import {
    DropdownMenu,
    DropdownMenuContent,
    DropdownMenuItem,
    DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu';
import { Input } from '@/components/ui/input';
import { Skeleton } from '@/components/ui/skeleton';
import {
    Table,
    TableBody,
    TableCell,
    TableHead,
    TableHeader,
    TableRow,
} from '@/components/ui/table';
import { Tabs, TabsList, TabsTrigger } from '@/components/ui/tabs';
import { useApiQuery } from '@/composables/useApiQuery';
import { useClinic } from '@/composables/useClinic';
import { usePagination } from '@/composables/usePagination';
import { api, ApiError } from '@/lib/api';
import {
    discountTargetLabels,
    discountTypeLabels,
    formatDiscountValue,
} from '@/lib/format';
import { cn } from '@/lib/utils';
import { discounts } from '@/routes/admin/clinics';
import { destroy, index } from '@/routes/clinic/discounts';
import type { Discount, DiscountTarget, Paginated } from '@/types';

const { clinicId, isAdmin, can } = useClinic();

setLayoutProps({
    breadcrumbs: [{ title: 'Discounts', href: discounts(clinicId.value) }],
});

type ScopeFilter = 'ALL' | 'CLINIC_WIDE' | 'PERSONAL';

const scope = ref<ScopeFilter>('ALL');
const search = ref('');
const debouncedSearch = refDebounced(search, 300);
const { page, perPage, query } = usePagination(25, [scope, debouncedSearch]);

const { data, loading, refresh } = useApiQuery<Paginated<Discount>>(() =>
    index.url(clinicId.value, {
        query: {
            scope: scope.value === 'ALL' ? undefined : scope.value,
            search: debouncedSearch.value || undefined,
            ...query.value,
        },
    }),
);

/** Same tone idiom as StatusBadge: teal for the clinic, amber for the member. */
const targetTones: Record<DiscountTarget, string> = {
    CLINIC: 'bg-teal-100 text-teal-800 dark:bg-teal-500/15 dark:text-teal-300',
    WORKER: 'bg-amber-100 text-amber-800 dark:bg-amber-500/15 dark:text-amber-300',
};

const formOpen = ref(false);
const editing = ref<Discount | null>(null);
const archiving = ref<Discount | null>(null);
const archiveOpen = ref(false);
const archiveProcessing = ref(false);

/**
 * A clinic-wide discount belongs to the clinic, so only an admin may touch it.
 * The list never returns another member's personal one, so anything personal
 * here is the viewer's own.
 */
function mayWrite(discount: Discount, permission: string): boolean {
    return (
        can(permission) && (discount.scope !== 'CLINIC_WIDE' || isAdmin.value)
    );
}

function openForm(discount: Discount | null): void {
    editing.value = discount;
    formOpen.value = true;
}

async function archive(): Promise<void> {
    if (!archiving.value) {
        return;
    }

    archiveProcessing.value = true;

    try {
        await api.delete(
            destroy.url({
                clinic: clinicId.value,
                discount: archiving.value.id,
            }),
        );
        toast.success(`${archiving.value.name} archived.`);
        archiveOpen.value = false;
        void refresh();
    } catch (error) {
        toast.error(
            error instanceof ApiError
                ? error.firstMessage
                : 'Could not archive the discount.',
        );
    } finally {
        archiveProcessing.value = false;
    }
}
</script>

<template>
    <Head title="Discounts" />

    <div class="flex flex-1 flex-col gap-6 p-4 md:p-6">
        <PageHeader
            title="Discounts"
            description="A discount takes money off what a patient is billed. “Comes out of” decides who absorbs it: the clinic's cut or the staff member's."
        >
            <template #actions>
                <div class="relative">
                    <Search
                        class="pointer-events-none absolute top-2.5 left-2.5 size-4 text-muted-foreground"
                    />
                    <Input
                        v-model="search"
                        placeholder="Search discounts"
                        class="w-56 pl-8"
                    />
                </div>
                <Button
                    v-if="can('clinic.discounts.store')"
                    @click="openForm(null)"
                >
                    <Plus />
                    New discount
                </Button>
            </template>
        </PageHeader>

        <Tabs v-model="scope">
            <TabsList>
                <TabsTrigger value="ALL">All</TabsTrigger>
                <TabsTrigger value="CLINIC_WIDE">Clinic-wide</TabsTrigger>
                <TabsTrigger value="PERSONAL">My discounts</TabsTrigger>
            </TabsList>
        </Tabs>

        <Card
            class="animate__animated animate__fadeInUp overflow-hidden py-0"
            style="--animate-duration: 0.5s"
        >
            <Table>
                <TableHeader>
                    <TableRow>
                        <TableHead>Name</TableHead>
                        <TableHead>Who can use it</TableHead>
                        <TableHead class="hidden md:table-cell">Type</TableHead>
                        <TableHead class="text-right">Value</TableHead>
                        <TableHead>Comes out of</TableHead>
                        <TableHead>Status</TableHead>
                        <TableHead class="w-10" />
                    </TableRow>
                </TableHeader>
                <TableBody>
                    <template v-if="loading && !data">
                        <TableRow v-for="row in 4" :key="row">
                            <TableCell colspan="7"
                                ><Skeleton class="h-6 w-full"
                            /></TableCell>
                        </TableRow>
                    </template>
                    <TableRow
                        v-for="discount in data?.data ?? []"
                        :key="discount.id"
                    >
                        <TableCell class="font-medium">{{
                            discount.name
                        }}</TableCell>
                        <TableCell>
                            <div class="flex flex-col items-start gap-1">
                                <Badge
                                    :variant="
                                        discount.scope === 'CLINIC_WIDE'
                                            ? 'secondary'
                                            : 'outline'
                                    "
                                >
                                    {{
                                        discount.scope === 'CLINIC_WIDE'
                                            ? 'Clinic-wide'
                                            : 'Personal'
                                    }}
                                </Badge>
                                <span
                                    v-if="discount.owner?.user?.name"
                                    class="text-xs text-muted-foreground"
                                    >{{ discount.owner.user.name }}</span
                                >
                            </div>
                        </TableCell>
                        <TableCell
                            class="hidden text-muted-foreground md:table-cell"
                            >{{ discountTypeLabels[discount.type] }}</TableCell
                        >
                        <TableCell class="text-right tabular-nums">{{
                            formatDiscountValue(discount.type, discount.value)
                        }}</TableCell>
                        <TableCell>
                            <Badge
                                variant="secondary"
                                :class="
                                    cn(
                                        'border-transparent',
                                        targetTones[discount.target],
                                    )
                                "
                            >
                                {{ discountTargetLabels[discount.target] }}
                            </Badge>
                        </TableCell>
                        <TableCell>
                            <Badge
                                variant="secondary"
                                :class="
                                    cn(
                                        'border-transparent',
                                        discount.is_active
                                            ? 'bg-emerald-100 text-emerald-800 dark:bg-emerald-500/15 dark:text-emerald-300'
                                            : 'bg-muted text-muted-foreground',
                                    )
                                "
                            >
                                {{ discount.is_active ? 'Active' : 'Off' }}
                            </Badge>
                        </TableCell>
                        <TableCell>
                            <DropdownMenu
                                v-if="
                                    mayWrite(
                                        discount,
                                        'clinic.discounts.update',
                                    ) ||
                                    mayWrite(
                                        discount,
                                        'clinic.discounts.destroy',
                                    )
                                "
                            >
                                <DropdownMenuTrigger as-child>
                                    <Button
                                        variant="ghost"
                                        size="icon"
                                        class="size-8"
                                        :aria-label="`Actions for ${discount.name}`"
                                    >
                                        <MoreHorizontal />
                                    </Button>
                                </DropdownMenuTrigger>
                                <DropdownMenuContent align="end">
                                    <DropdownMenuItem
                                        v-if="
                                            mayWrite(
                                                discount,
                                                'clinic.discounts.update',
                                            )
                                        "
                                        @select="openForm(discount)"
                                    >
                                        <Pencil />
                                        Edit
                                    </DropdownMenuItem>
                                    <DropdownMenuItem
                                        v-if="
                                            mayWrite(
                                                discount,
                                                'clinic.discounts.destroy',
                                            )
                                        "
                                        variant="destructive"
                                        @select="
                                            archiving = discount;
                                            archiveOpen = true;
                                        "
                                    >
                                        <Archive />
                                        Archive
                                    </DropdownMenuItem>
                                </DropdownMenuContent>
                            </DropdownMenu>
                        </TableCell>
                    </TableRow>
                </TableBody>
            </Table>
            <EmptyState
                v-if="data && data.data.length === 0"
                title="No discounts yet"
                description="A Clinic Admin makes clinic-wide discounts that everyone can bill with; a personal one is yours alone, and nobody else sees it."
            />
        </Card>

        <DataPagination
            v-if="data"
            v-model:page="page"
            v-model:per-page="perPage"
            :meta="data.meta"
        />
    </div>

    <DiscountFormDialog
        v-model:open="formOpen"
        :discount="editing"
        @saved="
            (discount) => {
                toast.success(`${discount.name} saved.`);
                refresh();
            }
        "
    />
    <ConfirmDialog
        v-model:open="archiveOpen"
        title="Archive this discount?"
        :description="`${archiving?.name} can no longer be picked when billing. Past bills keep what it already took off.`"
        confirm-label="Archive"
        :processing="archiveProcessing"
        @confirm="archive"
    />
</template>
