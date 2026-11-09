<script setup lang="ts">
import { Link, usePage } from '@inertiajs/vue3';
import { Building2, Check, ChevronsUpDown } from '@lucide/vue';
import { computed } from 'vue';
import {
    DropdownMenu,
    DropdownMenuContent,
    DropdownMenuItem,
    DropdownMenuLabel,
    DropdownMenuSeparator,
    DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu';
import {
    SidebarMenu,
    SidebarMenuButton,
    SidebarMenuItem,
    useSidebar,
} from '@/components/ui/sidebar';
import { dashboard } from '@/routes';
import { home } from '@/routes/admin/clinics';
import type { ClinicContext } from '@/types';

const props = defineProps<{ context: ClinicContext }>();

const page = usePage();
const clinics = computed(() => page.props.auth.clinics);
const { isMobile } = useSidebar();
const roleLabel = computed(() =>
    props.context.member.is_admin ? 'Clinic Admin' : 'Clinic staff',
);
</script>

<template>
    <SidebarMenu>
        <SidebarMenuItem>
            <DropdownMenu>
                <DropdownMenuTrigger as-child>
                    <SidebarMenuButton
                        size="lg"
                        class="data-[state=open]:bg-sidebar-accent data-[state=open]:text-sidebar-accent-foreground"
                    >
                        <div
                            class="flex aspect-square size-8 items-center justify-center rounded-md bg-teal-600 text-white"
                        >
                            <Building2 class="size-4" />
                        </div>
                        <div
                            class="grid flex-1 text-left text-sm leading-tight"
                        >
                            <span class="truncate font-semibold">{{
                                context.clinic.name
                            }}</span>
                            <span
                                class="truncate text-xs text-muted-foreground"
                                >{{ roleLabel }}</span
                            >
                        </div>
                        <ChevronsUpDown class="ml-auto size-4" />
                    </SidebarMenuButton>
                </DropdownMenuTrigger>
                <DropdownMenuContent
                    class="w-(--reka-dropdown-menu-trigger-width) min-w-56 rounded-lg"
                    :side="isMobile ? 'bottom' : 'right'"
                    align="start"
                    :side-offset="4"
                >
                    <DropdownMenuLabel class="text-xs text-muted-foreground"
                        >Your clinics</DropdownMenuLabel
                    >
                    <DropdownMenuItem
                        v-for="clinic in clinics"
                        :key="clinic.id"
                        as-child
                    >
                        <Link
                            :href="home(clinic.id)"
                            class="flex w-full cursor-pointer items-center gap-2"
                        >
                            <Building2 class="size-4" />
                            <span class="truncate">{{ clinic.name }}</span>
                            <Check
                                v-if="clinic.id === context.clinic.id"
                                class="ml-auto size-4"
                            />
                        </Link>
                    </DropdownMenuItem>
                    <template v-if="clinics.length > 1">
                        <DropdownMenuSeparator />
                        <DropdownMenuItem as-child>
                            <Link
                                :href="dashboard()"
                                class="w-full cursor-pointer"
                                >All clinics</Link
                            >
                        </DropdownMenuItem>
                    </template>
                </DropdownMenuContent>
            </DropdownMenu>
        </SidebarMenuItem>
    </SidebarMenu>
</template>
