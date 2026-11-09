<script setup lang="ts">
import { useHttp } from '@inertiajs/vue3';
import { Building, UserRound } from '@lucide/vue';
import type { Component } from 'vue';
import { computed, watch } from 'vue';
import FormField from '@/components/FormField.vue';
import InputError from '@/components/InputError.vue';
import { Button } from '@/components/ui/button';
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
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import { Spinner } from '@/components/ui/spinner';
import { useClinic } from '@/composables/useClinic';
import { discountTargetLabels, discountTypeLabels } from '@/lib/format';
import { cn } from '@/lib/utils';
import { store, update } from '@/routes/clinic/discounts';
import type {
    Discount,
    DiscountScope,
    DiscountTarget,
    DiscountType,
    Wrapped,
} from '@/types';

const props = defineProps<{ discount?: Discount | null }>();
const open = defineModel<boolean>('open', { required: true });
const emit = defineEmits<{ saved: [discount: Discount] }>();

const { clinicId, isAdmin } = useClinic();

type DiscountForm = {
    scope: DiscountScope;
    name: string;
    type: DiscountType;
    target: DiscountTarget;
    value: string;
    is_active: boolean;
};

/** Whose cut pays for the discount, and what that leaves the other side. */
const targets: Array<{
    value: DiscountTarget;
    icon: Component;
    hint: string;
}> = [
    {
        value: 'CLINIC',
        icon: Building,
        hint: 'The clinic absorbs it. The staff member is still paid on the full price.',
    },
    {
        value: 'WORKER',
        icon: UserRound,
        hint: 'The staff member absorbs it. The clinic keeps its full cut.',
    },
];

const form = useHttp<DiscountForm, Wrapped<Discount>>({
    scope: 'PERSONAL',
    name: '',
    type: 'PERCENTAGE',
    target: 'CLINIC',
    value: '',
    is_active: true,
});

// Who can use a discount is fixed when it is created, so an edit never sends it.
form.transform(
    (data) =>
        (props.discount
            ? {
                  name: data.name,
                  type: data.type,
                  target: data.target,
                  value: data.value,
                  is_active: data.is_active,
              }
            : data) as DiscountForm,
);

const isPercentage = computed(() => form.type === 'PERCENTAGE');

watch(open, (isOpen) => {
    if (!isOpen) {
        return;
    }

    const discount = props.discount;

    form.defaults({
        // A Clinic Admin is usually setting up a discount for the whole clinic.
        scope: discount?.scope ?? (isAdmin.value ? 'CLINIC_WIDE' : 'PERSONAL'),
        name: discount?.name ?? '',
        type: discount?.type ?? 'PERCENTAGE',
        target: discount?.target ?? 'CLINIC',
        value: discount?.value ?? '',
        is_active: discount?.is_active ?? true,
    })
        .reset()
        .clearErrors();
});

async function submit(): Promise<void> {
    const options = {
        onSuccess: (response: Wrapped<Discount>) => {
            emit('saved', response.data);
            open.value = false;
        },
    };

    if (props.discount) {
        await form.patch(
            update.url({ clinic: clinicId.value, discount: props.discount.id }),
            options,
        );
    } else {
        await form.post(store.url(clinicId.value), options);
    }
}
</script>

<template>
    <Dialog v-model:open="open">
        <DialogContent class="sm:max-w-lg">
            <DialogHeader>
                <DialogTitle>{{
                    discount ? 'Edit discount' : 'New discount'
                }}</DialogTitle>
                <DialogDescription v-if="discount">
                    {{
                        discount.scope === 'CLINIC_WIDE'
                            ? 'Everyone in the clinic can bill with this one.'
                            : 'Only you can see and bill with this one.'
                    }}
                    Who can use it never changes, and past bills keep what it
                    already took off.
                </DialogDescription>
                <DialogDescription v-else>
                    A discount takes money off what a patient is billed. Say how
                    much it takes off and whose cut pays for it.
                </DialogDescription>
            </DialogHeader>

            <form
                id="discount-form"
                class="grid gap-4"
                @submit.prevent="submit"
            >
                <InputError v-if="discount" :message="form.errors.scope" />

                <FormField
                    v-else
                    label="Who can use it"
                    for="discount-scope"
                    :error="form.errors.scope"
                    :hint="
                        isAdmin
                            ? 'Clinic-wide is available to every member; personal is yours alone.'
                            : 'Only a Clinic Admin can create a clinic-wide discount.'
                    "
                >
                    <Select v-model="form.scope">
                        <SelectTrigger id="discount-scope" class="w-full">
                            <SelectValue />
                        </SelectTrigger>
                        <SelectContent>
                            <SelectItem
                                value="CLINIC_WIDE"
                                :disabled="!isAdmin"
                            >
                                Clinic-wide
                            </SelectItem>
                            <SelectItem value="PERSONAL">Personal</SelectItem>
                        </SelectContent>
                    </Select>
                </FormField>

                <FormField
                    label="Name"
                    for="discount-name"
                    :error="form.errors.name"
                    hint="What staff pick from when billing, e.g. “Senior citizen”."
                >
                    <Input
                        id="discount-name"
                        v-model="form.name"
                        v-focus
                        required
                    />
                </FormField>

                <div class="grid gap-4 sm:grid-cols-2">
                    <FormField
                        label="Type"
                        for="discount-type"
                        :error="form.errors.type"
                    >
                        <Select v-model="form.type">
                            <SelectTrigger id="discount-type" class="w-full">
                                <SelectValue />
                            </SelectTrigger>
                            <SelectContent>
                                <SelectItem value="PERCENTAGE">{{
                                    discountTypeLabels.PERCENTAGE
                                }}</SelectItem>
                                <SelectItem value="FIXED">{{
                                    discountTypeLabels.FIXED
                                }}</SelectItem>
                            </SelectContent>
                        </Select>
                    </FormField>

                    <FormField
                        label="Value"
                        for="discount-value"
                        :error="form.errors.value"
                        :hint="
                            isPercentage
                                ? '0–100.'
                                : 'A flat amount off the item.'
                        "
                    >
                        <div class="relative">
                            <Input
                                id="discount-value"
                                v-model="form.value"
                                type="number"
                                min="0"
                                :max="isPercentage ? 100 : undefined"
                                step="0.01"
                                class="pr-12"
                                required
                            />
                            <span
                                class="pointer-events-none absolute top-1/2 right-3 -translate-y-1/2 text-sm text-muted-foreground"
                            >
                                {{ isPercentage ? '%' : 'off' }}
                            </span>
                        </div>
                    </FormField>
                </div>

                <FormField
                    label="Comes out of"
                    :error="form.errors.target"
                    hint="The patient pays the same either way — this only decides who gives up the money."
                >
                    <div class="grid gap-2 sm:grid-cols-2">
                        <button
                            v-for="option in targets"
                            :key="option.value"
                            type="button"
                            :aria-pressed="form.target === option.value"
                            :class="
                                cn(
                                    'rounded-lg border bg-background p-3 text-left transition-colors hover:bg-accent/50',
                                    form.target === option.value &&
                                        'border-teal-600 ring-2 ring-teal-600',
                                )
                            "
                            @click="form.target = option.value"
                        >
                            <span
                                class="flex items-center gap-2 text-sm font-medium"
                            >
                                <component
                                    :is="option.icon"
                                    class="size-4 text-muted-foreground"
                                />
                                {{ discountTargetLabels[option.value] }}
                            </span>
                            <span
                                class="mt-1 block text-xs text-muted-foreground"
                            >
                                {{ option.hint }}
                            </span>
                        </button>
                    </div>
                </FormField>

                <label
                    class="flex cursor-pointer items-start gap-3 rounded-md border p-3"
                >
                    <Checkbox
                        :model-value="form.is_active"
                        @update:model-value="
                            (checked) => (form.is_active = checked === true)
                        "
                    />
                    <span class="grid gap-1">
                        <span class="text-sm font-medium"
                            >Available for billing</span
                        >
                        <span class="text-xs text-muted-foreground">
                            Turn this off to keep the discount on file without
                            offering it when billing.
                        </span>
                    </span>
                </label>
            </form>

            <DialogFooter>
                <Button variant="outline" @click="open = false">Cancel</Button>
                <Button
                    type="submit"
                    form="discount-form"
                    :disabled="form.processing"
                >
                    <Spinner v-if="form.processing" />
                    {{ discount ? 'Save changes' : 'Add discount' }}
                </Button>
            </DialogFooter>
        </DialogContent>
    </Dialog>
</template>
