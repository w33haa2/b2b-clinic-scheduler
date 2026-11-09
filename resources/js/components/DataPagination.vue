<script setup lang="ts">
import { watch } from 'vue';
import {
    Pagination,
    PaginationContent,
    PaginationEllipsis,
    PaginationItem,
    PaginationNext,
    PaginationPrevious,
} from '@/components/ui/pagination';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import type { Paginated } from '@/types';

/**
 * Pager for every paginated API list (`meta` from a Laravel paginator):
 * "1–25 of 80", a rows-per-page selector (when `v-model:per-page` is bound)
 * and numbered pages with ellipses.
 */
type Props = {
    meta: Paginated<unknown>['meta'];
    perPageOptions?: number[];
};

const props = withDefaults(defineProps<Props>(), {
    perPageOptions: () => [10, 25, 50, 100],
});
const page = defineModel<number>('page', { required: true });
const perPage = defineModel<number>('perPage');

// A deleted last row can leave us past the end (e.g. page 4 of 3): step back.
watch(
    () => props.meta,
    (meta) => {
        if (meta.last_page > 0 && meta.current_page > meta.last_page) {
            page.value = meta.last_page;
        }
    },
);

function changePerPage(value: unknown): void {
    perPage.value = Number(value);
    page.value = 1;
}
</script>

<template>
    <nav
        v-if="meta.total > 0"
        aria-label="Pagination"
        class="flex flex-col-reverse items-center justify-between gap-3 text-sm text-muted-foreground sm:flex-row"
    >
        <div class="flex items-center gap-4">
            <span class="tabular-nums">
                <template v-if="meta.from"
                    >{{ meta.from }}–{{ meta.to }} of {{ meta.total }}</template
                >
                <template v-else>0 of {{ meta.total }}</template>
            </span>
            <!-- Not a <label>: a label re-clicks the trigger and closes the menu straight away. -->
            <div v-if="perPage !== undefined" class="flex items-center gap-2">
                <span id="rows-per-page-label" class="hidden sm:inline"
                    >Rows per page</span
                >
                <Select
                    :model-value="String(perPage)"
                    @update:model-value="changePerPage"
                >
                    <SelectTrigger
                        size="sm"
                        class="w-18"
                        aria-labelledby="rows-per-page-label"
                        aria-label="Rows per page"
                    >
                        <SelectValue />
                    </SelectTrigger>
                    <SelectContent>
                        <SelectItem
                            v-for="option in perPageOptions"
                            :key="option"
                            :value="String(option)"
                        >
                            {{ option }}
                        </SelectItem>
                    </SelectContent>
                </Select>
            </div>
        </div>

        <Pagination
            v-if="meta.last_page > 1"
            v-slot="{ page: current }"
            :page="meta.current_page"
            :total="meta.total"
            :items-per-page="meta.per_page"
            :sibling-count="1"
            show-edges
            class="mx-0 w-auto"
            @update:page="(value: number) => (page = value)"
        >
            <PaginationContent v-slot="{ items }">
                <PaginationPrevious />
                <template v-for="(item, index) in items" :key="index">
                    <PaginationItem
                        v-if="item.type === 'page'"
                        :value="item.value"
                        :is-active="item.value === current"
                        class="tabular-nums"
                    >
                        {{ item.value }}
                    </PaginationItem>
                    <PaginationEllipsis v-else :index="index" />
                </template>
                <PaginationNext />
            </PaginationContent>
        </Pagination>
    </nav>
</template>
