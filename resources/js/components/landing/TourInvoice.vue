<script setup lang="ts">
import MockFrame from './MockFrame.vue';

const items = [
    { name: 'Group physio', qty: 1, price: '900.00', amount: '900.00' },
    { name: 'Resistance band', qty: 1, price: '350.00', amount: '350.00' },
    {
        name: 'Follow-up assessment',
        qty: 1,
        price: '1,200.00',
        amount: '1,200.00',
    },
];
</script>

<template>
    <MockFrame
        title="INV-000214"
        label="An invoice for one patient with three billed services and a total."
    >
        <div class="space-y-5 p-5 text-xs">
            <div class="flex items-start justify-between">
                <div>
                    <div class="font-display text-2xl tracking-tight">
                        Invoice
                    </div>
                    <div class="text-muted-foreground tabular-nums">
                        Invoice #000214 · Issued 4 Nov
                    </div>
                </div>
                <span
                    class="rounded-full bg-muted px-2 py-0.5 text-muted-foreground"
                    >Northside Physio</span
                >
            </div>
            <div class="grid grid-cols-2 gap-4">
                <div>
                    <div class="text-muted-foreground">Bill to</div>
                    <div class="font-medium">Marco Santos</div>
                </div>
                <div>
                    <div class="text-muted-foreground">Sessions</div>
                    <div class="font-medium">3 Nov, 4 Nov</div>
                </div>
            </div>
            <div class="divide-y border-y">
                <div
                    class="grid grid-cols-[1fr_2rem_4.5rem_4.5rem] py-1.5 text-muted-foreground"
                >
                    <span>Service</span><span class="text-right">Qty</span
                    ><span class="text-right">Price</span
                    ><span class="text-right">Amount</span>
                </div>
                <div
                    v-for="item in items"
                    :key="item.name"
                    class="grid grid-cols-[1fr_2rem_4.5rem_4.5rem] py-1.5"
                >
                    <span>{{ item.name }}</span>
                    <span class="text-right">{{ item.qty }}</span>
                    <span class="text-right tabular-nums">{{
                        item.price
                    }}</span>
                    <span class="text-right font-medium tabular-nums">{{
                        item.amount
                    }}</span>
                </div>
            </div>
            <div class="flex items-baseline justify-between">
                <span class="font-medium">Total</span>
                <span class="text-lg font-semibold tabular-nums">2,450.00</span>
            </div>
        </div>
    </MockFrame>
</template>
