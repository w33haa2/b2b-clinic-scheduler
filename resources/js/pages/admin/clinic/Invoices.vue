<script setup lang="ts">
import { Head, setLayoutProps } from '@inertiajs/vue3';
import {
    Download,
    Eye,
    MoreHorizontal,
    Plus,
    Printer,
    ReceiptText,
    Trash2,
} from '@lucide/vue';
import { ref } from 'vue';
import { toast } from 'vue-sonner';
import InvoiceCreateDialog from '@/components/clinic/InvoiceCreateDialog.vue';
import ConfirmDialog from '@/components/ConfirmDialog.vue';
import DataPagination from '@/components/DataPagination.vue';
import EmptyState from '@/components/EmptyState.vue';
import PageHeader from '@/components/PageHeader.vue';
import { Button } from '@/components/ui/button';
import { Card } from '@/components/ui/card';
import {
    DropdownMenu,
    DropdownMenuContent,
    DropdownMenuItem,
    DropdownMenuSeparator,
    DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu';
import { Separator } from '@/components/ui/separator';
import {
    Sheet,
    SheetContent,
    SheetDescription,
    SheetHeader,
    SheetTitle,
} from '@/components/ui/sheet';
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
import { useClinic } from '@/composables/useClinic';
import { api, ApiError } from '@/lib/api';
import { downloadFile } from '@/lib/download';
import { formatDate, formatMoney, patientName } from '@/lib/format';
import { invoices } from '@/routes/admin/clinics';
import { destroy, index, pdf } from '@/routes/clinic/invoices';
import type { Invoice, Paginated } from '@/types';

const { clinicId, context, can } = useClinic();

setLayoutProps({
    breadcrumbs: [{ title: 'Invoices', href: invoices(clinicId.value) }],
});

const { page, perPage, query } = usePagination();
const { data, loading, refresh } = useApiQuery<Paginated<Invoice>>(() =>
    index.url(clinicId.value, { query: query.value }),
);

const createOpen = ref(false);
const viewing = ref<Invoice | null>(null);
const viewOpen = ref(false);
const deleteOpen = ref(false);
const deleting = ref(false);

function view(invoice: Invoice): void {
    viewing.value = invoice;
    viewOpen.value = true;
}

async function deleteInvoice(): Promise<void> {
    if (!viewing.value) {
        return;
    }

    deleting.value = true;

    try {
        await api.delete(
            destroy.url({ clinic: clinicId.value, invoice: viewing.value.id }),
        );
        toast.success(
            `${viewing.value.reference} deleted. Its items can be invoiced again.`,
        );
        deleteOpen.value = false;
        viewOpen.value = false;
        void refresh();
    } catch (error) {
        toast.error(
            error instanceof ApiError
                ? error.firstMessage
                : 'Could not delete the invoice.',
        );
    } finally {
        deleting.value = false;
    }
}

function print(): void {
    window.print();
}

function confirmDelete(invoice: Invoice): void {
    viewing.value = invoice;
    deleteOpen.value = true;
}

/** Invoice IDs whose PDF is being generated, for the row spinner. */
const downloading = ref<number[]>([]);

async function downloadPdf(invoice: Invoice): Promise<void> {
    downloading.value = [...downloading.value, invoice.id];

    try {
        await downloadFile(
            pdf.url({ clinic: clinicId.value, invoice: invoice.id }),
            `${invoice.reference}.pdf`,
        );
    } catch (error) {
        toast.error(
            error instanceof ApiError
                ? error.message
                : 'Could not download the invoice.',
        );
    } finally {
        downloading.value = downloading.value.filter((id) => id !== invoice.id);
    }
}
</script>

<template>
    <Head title="Invoices" />

    <div class="flex flex-1 flex-col gap-6 p-4 md:p-6">
        <PageHeader
            title="Invoices"
            description="Statements of paid services, one patient per invoice."
        >
            <template #actions>
                <Button
                    v-if="
                        can('clinic.invoices.store') &&
                        can('clinic.patients.uninvoiced-items.index')
                    "
                    @click="createOpen = true"
                >
                    <Plus />
                    New invoice
                </Button>
            </template>
        </PageHeader>

        <Card
            class="animate__animated animate__fadeInUp overflow-hidden py-0"
            style="--animate-duration: 0.5s"
        >
            <Table>
                <TableHeader>
                    <TableRow>
                        <TableHead>Invoice</TableHead>
                        <TableHead>Patient</TableHead>
                        <TableHead class="hidden md:table-cell"
                            >Issued</TableHead
                        >
                        <TableHead class="hidden md:table-cell"
                            >Items</TableHead
                        >
                        <TableHead class="text-right">Total</TableHead>
                        <TableHead class="w-12"
                            ><span class="sr-only">Actions</span></TableHead
                        >
                    </TableRow>
                </TableHeader>
                <TableBody>
                    <template v-if="loading && !data">
                        <TableRow v-for="row in 4" :key="row">
                            <TableCell colspan="6"
                                ><Skeleton class="h-6 w-full"
                            /></TableCell>
                        </TableRow>
                    </template>
                    <TableRow
                        v-for="invoice in data?.data ?? []"
                        :key="invoice.id"
                        class="cursor-pointer"
                        @click="view(invoice)"
                    >
                        <TableCell class="font-medium tabular-nums">{{
                            invoice.reference
                        }}</TableCell>
                        <TableCell>{{
                            patientName(invoice.patient)
                        }}</TableCell>
                        <TableCell
                            class="hidden text-muted-foreground md:table-cell"
                            >{{ formatDate(invoice.issued_at) }}</TableCell
                        >
                        <TableCell
                            class="hidden text-muted-foreground md:table-cell"
                            >{{ invoice.items?.length ?? 0 }}</TableCell
                        >
                        <TableCell
                            class="text-right font-medium tabular-nums"
                            >{{ formatMoney(invoice.total ?? 0) }}</TableCell
                        >
                        <TableCell class="text-right" @click.stop>
                            <DropdownMenu>
                                <DropdownMenuTrigger as-child>
                                    <Button
                                        variant="ghost"
                                        size="icon"
                                        class="size-8"
                                        :aria-label="`Actions for ${invoice.reference}`"
                                    >
                                        <Spinner
                                            v-if="
                                                downloading.includes(invoice.id)
                                            "
                                        />
                                        <MoreHorizontal v-else />
                                    </Button>
                                </DropdownMenuTrigger>
                                <DropdownMenuContent align="end" class="w-44">
                                    <DropdownMenuItem @select="view(invoice)">
                                        <Eye />
                                        View
                                    </DropdownMenuItem>
                                    <DropdownMenuItem
                                        :disabled="
                                            downloading.includes(invoice.id)
                                        "
                                        @select="downloadPdf(invoice)"
                                    >
                                        <Download />
                                        Download PDF
                                    </DropdownMenuItem>
                                    <template
                                        v-if="can('clinic.invoices.destroy')"
                                    >
                                        <DropdownMenuSeparator />
                                        <DropdownMenuItem
                                            variant="destructive"
                                            @select="confirmDelete(invoice)"
                                        >
                                            <Trash2 />
                                            Delete
                                        </DropdownMenuItem>
                                    </template>
                                </DropdownMenuContent>
                            </DropdownMenu>
                        </TableCell>
                    </TableRow>
                </TableBody>
            </Table>
            <EmptyState
                v-if="data && data.data.length === 0"
                title="No invoices yet"
                description="Bill services to patients on the schedule, mark them paid, then create an invoice here."
            />
        </Card>

        <DataPagination
            v-if="data"
            v-model:page="page"
            v-model:per-page="perPage"
            :meta="data.meta"
        />
    </div>

    <InvoiceCreateDialog
        v-model:open="createOpen"
        @saved="
            (invoice) => {
                toast.success(`${invoice.reference} created.`);
                refresh();
                view(invoice);
            }
        "
    />

    <Sheet v-model:open="viewOpen">
        <SheetContent class="w-full overflow-y-auto sm:max-w-lg">
            <template v-if="viewing">
                <SheetHeader class="border-b">
                    <SheetTitle class="flex items-center gap-2">
                        <ReceiptText class="size-5 text-teal-600" />
                        {{ viewing.reference }}
                    </SheetTitle>
                    <SheetDescription
                        >Issued
                        {{ formatDate(viewing.issued_at) }}</SheetDescription
                    >
                </SheetHeader>

                <div class="printable-invoice space-y-6 p-4">
                    <div class="grid grid-cols-2 gap-4 text-sm">
                        <div>
                            <div class="text-muted-foreground">From</div>
                            <div class="font-medium">
                                {{ context.clinic.name }}
                            </div>
                        </div>
                        <div>
                            <div class="text-muted-foreground">Bill to</div>
                            <div class="font-medium">
                                {{ patientName(viewing.patient) }}
                            </div>
                            <div class="text-muted-foreground">
                                {{ viewing.patient?.email }}
                            </div>
                        </div>
                    </div>

                    <Table>
                        <TableHeader>
                            <TableRow>
                                <TableHead>Service</TableHead>
                                <TableHead class="text-right">Qty</TableHead>
                                <TableHead class="text-right">Price</TableHead>
                                <TableHead class="text-right">Amount</TableHead>
                            </TableRow>
                        </TableHeader>
                        <TableBody>
                            <TableRow
                                v-for="item in viewing.items ?? []"
                                :key="item.id"
                            >
                                <TableCell>{{
                                    item.line_item?.name
                                }}</TableCell>
                                <TableCell class="text-right">{{
                                    item.quantity
                                }}</TableCell>
                                <TableCell class="text-right tabular-nums">{{
                                    formatMoney(item.unit_price)
                                }}</TableCell>
                                <TableCell class="text-right tabular-nums">{{
                                    formatMoney(item.amount)
                                }}</TableCell>
                            </TableRow>
                        </TableBody>
                    </Table>

                    <Separator />
                    <div class="flex justify-between text-base font-semibold">
                        <span>Total</span>
                        <span class="tabular-nums">{{
                            formatMoney(viewing.total ?? 0)
                        }}</span>
                    </div>
                    <p
                        v-if="viewing.notes"
                        class="rounded-md bg-muted/50 p-3 text-sm whitespace-pre-line"
                    >
                        {{ viewing.notes }}
                    </p>
                </div>

                <div class="flex gap-2 px-4 pb-4 print:hidden">
                    <Button
                        :disabled="downloading.includes(viewing.id)"
                        @click="downloadPdf(viewing)"
                    >
                        <Spinner v-if="downloading.includes(viewing.id)" />
                        <Download v-else />
                        Download PDF
                    </Button>
                    <Button variant="outline" @click="print">
                        <Printer />
                        Print
                    </Button>
                    <Button
                        v-if="can('clinic.invoices.destroy')"
                        variant="ghost"
                        class="text-destructive"
                        @click="deleteOpen = true"
                    >
                        <Trash2 />
                        Delete
                    </Button>
                </div>
            </template>
        </SheetContent>
    </Sheet>

    <ConfirmDialog
        v-model:open="deleteOpen"
        :title="`Delete ${viewing?.reference}?`"
        description="The billed services on it become available to invoice again."
        :processing="deleting"
        @confirm="deleteInvoice"
    />
</template>
