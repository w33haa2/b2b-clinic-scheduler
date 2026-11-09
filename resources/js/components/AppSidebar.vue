<script setup lang="ts">
import { Link, usePage } from '@inertiajs/vue3';
import {
    Building2,
    CalendarCheck2,
    CalendarDays,
    LayoutGrid,
    PiggyBank,
    ReceiptText,
    ShieldCheck,
    SlidersHorizontal,
    Stethoscope,
    TicketPercent,
    UserCog,
    Users,
    Wallet,
    Contact,
    History,
    HandCoins,
    HandHeart,
    LineChart,
    Repeat,
    ClipboardList,
} from '@lucide/vue';
import { computed } from 'vue';
import AppLogo from '@/components/AppLogo.vue';
import ClinicSwitcher from '@/components/ClinicSwitcher.vue';
import NavMain from '@/components/NavMain.vue';
import NavUser from '@/components/NavUser.vue';
import {
    Sidebar,
    SidebarContent,
    SidebarFooter,
    SidebarHeader,
    SidebarMenu,
    SidebarMenuButton,
    SidebarMenuItem,
} from '@/components/ui/sidebar';
import { useCurrentUrl } from '@/composables/useCurrentUrl';
import { dashboard } from '@/routes';
import * as clinicPages from '@/routes/admin/clinics';
import {
    activity as internalActivity,
    superAdmins,
} from '@/routes/admin/internal';
import { index as internalClinics } from '@/routes/admin/internal/clinics';
import type { NavItem } from '@/types';

const page = usePage();
const { isCurrentUrl } = useCurrentUrl();
const clinicContext = computed(() => page.props.clinicContext);
const isSuperAdmin = computed(
    () => page.props.auth.user?.is_super_admin === true,
);

/** Clinic pages in the same order as ClinicHomeController::PAGES, with the permission each needs. */
const clinicNav = computed<NavItem[]>(() => {
    const context = clinicContext.value;

    if (!context) {
        return [];
    }

    const id = context.clinic.id;
    const can = (permission: string) =>
        context.member.is_admin ||
        context.member.permissions.includes(permission);

    // Schedule is a group: the calendar and the daily sessions page.
    const scheduleItems: Array<NavItem & { permission: string }> = [
        {
            title: 'Calendar',
            href: clinicPages.schedule(id),
            icon: CalendarDays,
            permission: 'clinic.sessions.index',
        },
        {
            title: 'Daily sessions',
            href: clinicPages.dailySessions(id),
            icon: Repeat,
            permission: 'clinic.daily-sessions.index',
        },
    ].filter((item) => can(item.permission));

    /*
     * Payouts is a group for admins: the runs themselves, the receivables
     * ledger and the split configuration. The last two are Clinic Admin
     * only, so a worker just gets the single "Payouts" link.
     */
    const payoutItems: NavItem[] = [
        ...(can('clinic.payouts.index')
            ? [
                  {
                      title: 'Payout runs',
                      href: clinicPages.payouts(id),
                      icon: PiggyBank,
                  },
              ]
            : []),
        ...(context.member.is_admin
            ? [
                  {
                      title: 'Receivables',
                      href: clinicPages.receivables(id),
                      icon: HandHeart,
                  },
                  {
                      title: 'Payout settings',
                      href: clinicPages.payoutSettings(id),
                      icon: SlidersHorizontal,
                  },
              ]
            : []),
    ];

    const payrollItems: NavItem[] = payoutItems.length
        ? [
              {
                  title: 'Payouts',
                  href: payoutItems[0].href,
                  icon: PiggyBank,
                  ...(payoutItems.length > 1 ? { items: payoutItems } : {}),
              },
          ]
        : [];

    /*
     * Patients is a group: the records themselves, the progress notes that
     * track how people are doing, and the builder for the form behind both.
     */
    const patientItems: Array<NavItem & { permission: string }> = [
        {
            title: 'Records',
            href: clinicPages.patients(id),
            icon: Contact,
            permission: 'clinic.patients.index',
        },
        {
            title: 'Progress notes',
            href: clinicPages.progressNotes(id),
            icon: LineChart,
            permission: 'clinic.progress-notes.index',
        },
        {
            title: 'Patient form',
            href: clinicPages.patientForm(id),
            icon: ClipboardList,
            permission: 'clinic.patient-form.show',
        },
    ].filter((item) => can(item.permission));

    const items: Array<NavItem & { permission?: string }> = [
        ...(scheduleItems.length
            ? [
                  {
                      title: 'Schedule',
                      href: scheduleItems[0].href,
                      icon: CalendarDays,
                      items: scheduleItems,
                  },
              ]
            : []),
        ...(patientItems.length
            ? [
                  {
                      title: 'Patients',
                      href: patientItems[0].href,
                      icon: Contact,
                      ...(patientItems.length > 1
                          ? { items: patientItems }
                          : {}),
                  },
              ]
            : []),
        {
            title: 'Services & pricing',
            href: clinicPages.services(id),
            icon: Stethoscope,
            permission: 'clinic.line-items.index',
        },
        {
            title: 'Discounts',
            href: clinicPages.discounts(id),
            icon: TicketPercent,
            permission: 'clinic.discounts.index',
        },
        {
            title: 'Payments',
            href: clinicPages.payments(id),
            icon: HandCoins,
            permission: 'clinic.payments.index',
        },
        {
            title: 'Invoices',
            href: clinicPages.invoices(id),
            icon: ReceiptText,
            permission: 'clinic.invoices.index',
        },
        {
            title: 'Transactions',
            href: clinicPages.transactions(id),
            icon: Wallet,
            permission: 'clinic.transactions.index',
        },
        {
            title: 'Attendance',
            href: clinicPages.attendance(id),
            icon: CalendarCheck2,
            permission: 'clinic.attendance.index',
        },
        ...payrollItems,
        {
            title: 'Staff',
            href: clinicPages.members(id),
            icon: Users,
            permission: 'clinic.members.index',
        },
        {
            title: 'Roles & permissions',
            href: clinicPages.roles(id),
            icon: UserCog,
            permission: 'clinic.roles.index',
        },
        {
            title: 'Activity log',
            href: clinicPages.activity(id),
            icon: History,
            permission: 'clinic.activity.index',
        },
    ];

    return items.filter((item) => !item.permission || can(item.permission));
});

const internalNav: NavItem[] = [
    { title: 'Clinics', href: internalClinics(), icon: Building2 },
    { title: 'Super admins', href: superAdmins(), icon: ShieldCheck },
    { title: 'Activity log', href: internalActivity(), icon: History },
];

const nav = computed<{ label: string; items: NavItem[] }>(() => {
    if (clinicContext.value) {
        return { label: 'Clinic', items: clinicNav.value };
    }

    if (isSuperAdmin.value) {
        return { label: 'Internal tool', items: internalNav };
    }

    return {
        label: 'Platform',
        items: [
            {
                title: 'My clinics',
                href: dashboard(),
                icon: LayoutGrid,
                isActive: isCurrentUrl(dashboard()),
            },
        ],
    };
});
</script>

<template>
    <Sidebar collapsible="icon" variant="inset">
        <SidebarHeader>
            <ClinicSwitcher v-if="clinicContext" :context="clinicContext" />
            <SidebarMenu v-else>
                <SidebarMenuItem>
                    <SidebarMenuButton size="lg" as-child>
                        <Link :href="dashboard()">
                            <AppLogo />
                        </Link>
                    </SidebarMenuButton>
                </SidebarMenuItem>
            </SidebarMenu>
        </SidebarHeader>

        <SidebarContent>
            <NavMain :label="nav.label" :items="nav.items" />
        </SidebarContent>

        <SidebarFooter>
            <NavUser />
        </SidebarFooter>
    </Sidebar>
    <slot />
</template>
