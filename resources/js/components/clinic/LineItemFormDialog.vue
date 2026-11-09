<script setup lang="ts">
import { useHttp } from '@inertiajs/vue3';
import { watch } from 'vue';
import FormField from '@/components/FormField.vue';
import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { Spinner } from '@/components/ui/spinner';
import { useClinic } from '@/composables/useClinic';
import { store, update } from '@/routes/clinic/line-items';
import type { LineItem, Wrapped } from '@/types';

const props = defineProps<{ lineItem?: LineItem | null }>();
const open = defineModel<boolean>('open', { required: true });
const emit = defineEmits<{ saved: [lineItem: LineItem] }>();

const { clinicId } = useClinic();
const form = useHttp<
    { item_code: string; name: string; price: string },
    Wrapped<LineItem>
>({
    item_code: '',
    name: '',
    price: '',
});

watch(open, (isOpen) => {
    if (isOpen) {
        form.defaults({
            item_code: props.lineItem?.item_code ?? '',
            name: props.lineItem?.name ?? '',
            price: props.lineItem?.price ?? '',
        })
            .reset()
            .clearErrors();
    }
});

async function submit(): Promise<void> {
    const options = {
        onSuccess: (response: Wrapped<LineItem>) => {
            emit('saved', response.data);
            open.value = false;
        },
    };

    if (props.lineItem) {
        await form.patch(
            update.url({ clinic: clinicId.value, lineItem: props.lineItem.id }),
            options,
        );
    } else {
        await form.post(store.url(clinicId.value), options);
    }
}
</script>

<template>
    <Dialog v-model:open="open">
        <DialogContent class="sm:max-w-md">
            <DialogHeader>
                <DialogTitle>{{
                    lineItem ? 'Edit service' : 'New service'
                }}</DialogTitle>
                <DialogDescription>
                    A price change only applies to items billed from now on;
                    past bills keep their price.
                </DialogDescription>
            </DialogHeader>
            <form
                id="line-item-form"
                class="grid gap-4"
                @submit.prevent="submit"
            >
                <FormField
                    label="Code"
                    for="item_code"
                    :error="form.errors.item_code"
                    hint="Short and unique, e.g. CONSULT."
                >
                    <Input
                        id="item_code"
                        v-model="form.item_code"
                        v-focus
                        class="uppercase"
                        required
                    />
                </FormField>
                <FormField
                    label="Name"
                    for="item_name"
                    :error="form.errors.name"
                >
                    <Input id="item_name" v-model="form.name" required />
                </FormField>
                <FormField
                    label="Price"
                    for="item_price"
                    :error="form.errors.price"
                >
                    <Input
                        id="item_price"
                        v-model="form.price"
                        type="number"
                        min="0"
                        step="0.01"
                        required
                    />
                </FormField>
            </form>
            <DialogFooter>
                <Button variant="outline" @click="open = false">Cancel</Button>
                <Button
                    type="submit"
                    form="line-item-form"
                    :disabled="form.processing"
                >
                    <Spinner v-if="form.processing" />
                    {{ lineItem ? 'Save changes' : 'Add service' }}
                </Button>
            </DialogFooter>
        </DialogContent>
    </Dialog>
</template>
