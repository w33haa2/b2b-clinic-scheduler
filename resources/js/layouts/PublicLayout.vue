<script setup lang="ts">
import { Link, usePage } from '@inertiajs/vue3';
import { useWindowScroll } from '@vueuse/core';
import { computed } from 'vue';
import AppLogoIcon from '@/components/AppLogoIcon.vue';
import ThemeToggle from '@/components/ThemeToggle.vue';
import { Button } from '@/components/ui/button';
import { cn } from '@/lib/utils';
import { dashboard, home, login } from '@/routes';

const page = usePage();
const user = computed(() => page.props.auth.user);
const contactEmail = computed(() => page.props.contactEmail);
const demoHref = computed(() =>
    contactEmail.value
        ? `mailto:${contactEmail.value}?subject=${encodeURIComponent(`${page.props.name} demo`)}`
        : '#',
);
const currentYear = new Date().getFullYear();

// The header gains a hairline and a solid background once the page scrolls under it.
const { y } = useWindowScroll();
const isScrolled = computed(() => y.value > 8);

const sections = [
    { href: '#day', label: 'Front desk' },
    { href: '#daily', label: 'Daily planner' },
    { href: '#product', label: 'Workspace' },
    { href: '#clinics', label: 'Practices' },
    { href: '#security', label: 'Security' },
    { href: '#faq', label: 'FAQ' },
];
</script>

<template>
    <div class="landing flex min-h-svh flex-col">
        <header
            :class="
                cn(
                    'sticky top-0 z-40 border-b border-transparent transition-colors duration-300',
                    isScrolled &&
                        'border-border bg-background/85 backdrop-blur-md',
                )
            "
        >
            <div
                class="mx-auto flex h-16 w-full max-w-6xl items-center gap-8 px-4 md:px-8"
            >
                <Link
                    :href="home()"
                    class="flex items-center gap-2 font-semibold tracking-tight"
                >
                    <AppLogoIcon class="size-6 fill-current text-primary" />
                    {{ page.props.name }}
                </Link>

                <nav
                    aria-label="Sections"
                    class="hidden items-center gap-6 text-sm text-muted-foreground md:flex"
                >
                    <a
                        v-for="section in sections"
                        :key="section.href"
                        :href="section.href"
                        class="transition-colors hover:text-foreground"
                    >
                        {{ section.label }}
                    </a>
                </nav>

                <div class="ml-auto flex items-center gap-2 sm:gap-4">
                    <ThemeToggle />
                    <Link
                        :href="user ? dashboard() : login()"
                        class="hidden text-sm font-medium sm:inline"
                    >
                        {{ user ? 'Open app' : 'Staff log in' }}
                    </Link>
                    <Button size="sm" as-child>
                        <a :href="demoHref">Book a demo</a>
                    </Button>
                </div>
            </div>
        </header>

        <main class="flex-1">
            <slot />
        </main>

        <footer class="border-t">
            <div
                class="mx-auto grid w-full max-w-6xl gap-10 px-4 py-14 text-sm md:grid-cols-[1.5fr_1fr_1fr] md:px-8"
            >
                <div class="space-y-3">
                    <div class="flex items-center gap-2 font-semibold">
                        <AppLogoIcon class="size-5 fill-current text-primary" />
                        {{ page.props.name }}
                    </div>
                    <p class="max-w-xs text-muted-foreground">
                        Scheduling, patient records, billing and invoices for
                        clinics.
                    </p>
                </div>
                <nav aria-label="Footer" class="space-y-2">
                    <p class="font-medium">Product</p>
                    <a
                        v-for="section in sections"
                        :key="section.href"
                        :href="section.href"
                        class="block text-muted-foreground hover:text-foreground"
                    >
                        {{ section.label }}
                    </a>
                </nav>
                <div class="space-y-2">
                    <p class="font-medium">Contact</p>
                    <a
                        v-if="contactEmail"
                        :href="`mailto:${contactEmail}`"
                        class="block text-muted-foreground hover:text-foreground"
                    >
                        {{ contactEmail }}
                    </a>
                    <Link
                        :href="login()"
                        class="block text-muted-foreground hover:text-foreground"
                        >Staff log in</Link
                    >
                </div>
            </div>
            <div
                class="mx-auto flex flex-col justify-between gap-2 border-t px-4 py-6 text-xs text-muted-foreground sm:flex-row sm:items-center md:px-8 lg:max-w-6xl"
            >
                <span>&copy; {{ currentYear }} {{ page.props.name }}</span>
                <span class="text-xs text-muted-foreground">
                    Multi-clinic scheduling, per-patient billing and invoices
                </span>
            </div>
        </footer>
    </div>
</template>
