<script setup lang="ts">
import { Check, ChevronsUpDown, Globe } from '@lucide/vue';
import { computed, ref } from 'vue';
import { Button } from '@/components/ui/button';
import {
    Command,
    CommandEmpty,
    CommandGroup,
    CommandInput,
    CommandItem,
    CommandList,
} from '@/components/ui/command';
import {
    Popover,
    PopoverContent,
    PopoverTrigger,
} from '@/components/ui/popover';

/**
 * Searchable list of IANA timezones (e.g. "Asia/Manila"). v-model is the zone name.
 */
defineProps<{ id?: string }>();

const model = defineModel<string>({ default: '' });
const open = ref(false);
const timezones = computed(() => Intl.supportedValuesOf('timeZone'));

function choose(zone: string): void {
    model.value = zone;
    open.value = false;
}
</script>

<template>
    <Popover v-model:open="open">
        <PopoverTrigger as-child>
            <Button
                :id="id"
                type="button"
                variant="outline"
                role="combobox"
                :aria-expanded="open"
                class="w-full justify-between px-3 font-normal"
            >
                <span class="flex items-center gap-2 truncate">
                    <Globe class="size-4 text-muted-foreground" />
                    {{ model || 'Choose a timezone' }}
                </span>
                <ChevronsUpDown class="size-4 opacity-50" />
            </Button>
        </PopoverTrigger>
        <PopoverContent
            class="w-(--reka-popover-trigger-width) p-0"
            align="start"
        >
            <Command>
                <CommandInput placeholder="Search timezones" />
                <CommandList class="max-h-64">
                    <CommandEmpty>No timezone found.</CommandEmpty>
                    <CommandGroup>
                        <CommandItem
                            v-for="zone in timezones"
                            :key="zone"
                            :value="zone"
                            @select="choose(zone)"
                        >
                            <Check
                                class="size-4"
                                :class="
                                    zone === model ? 'opacity-100' : 'opacity-0'
                                "
                            />
                            {{ zone.replaceAll('_', ' ') }}
                        </CommandItem>
                    </CommandGroup>
                </CommandList>
            </Command>
        </PopoverContent>
    </Popover>
</template>
