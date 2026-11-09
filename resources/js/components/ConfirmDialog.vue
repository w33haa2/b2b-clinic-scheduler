<script setup lang="ts">
import {
    AlertDialog,
    AlertDialogCancel,
    AlertDialogContent,
    AlertDialogDescription,
    AlertDialogFooter,
    AlertDialogHeader,
    AlertDialogTitle,
} from '@/components/ui/alert-dialog';
import { Button } from '@/components/ui/button';
import { Spinner } from '@/components/ui/spinner';

type Props = {
    title: string;
    description?: string;
    confirmLabel?: string;
    processing?: boolean;
};

withDefaults(defineProps<Props>(), {
    confirmLabel: 'Delete',
    processing: false,
});

const open = defineModel<boolean>('open', { required: true });
const emit = defineEmits<{ confirm: [] }>();
</script>

<template>
    <AlertDialog v-model:open="open">
        <AlertDialogContent>
            <AlertDialogHeader>
                <AlertDialogTitle>{{ title }}</AlertDialogTitle>
                <AlertDialogDescription v-if="description">
                    {{ description }}
                </AlertDialogDescription>
            </AlertDialogHeader>
            <AlertDialogFooter>
                <AlertDialogCancel :disabled="processing"
                    >Cancel</AlertDialogCancel
                >
                <Button
                    variant="destructive"
                    :disabled="processing"
                    @click="emit('confirm')"
                >
                    <Spinner v-if="processing" />
                    {{ confirmLabel }}
                </Button>
            </AlertDialogFooter>
        </AlertDialogContent>
    </AlertDialog>
</template>
