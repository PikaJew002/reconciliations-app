<script setup>
    import OnboardingChecklist from '../Components/Onboarding/OnboardingChecklist.vue';
    import { useDriverTour } from '../Composables/useDriverTour';
    import { useOnboarding } from '../Composables/useOnboarding';
    import { Link, usePage } from '@inertiajs/vue3';
    import { computed } from 'vue';

    let page = usePage();
    let user = computed(() => page.props.auth.user);
    let currentUrl = computed(() => page.url.split('?')[0]);
    let {
        isPresent,
        isVisible,
        steps,
        panelOpen,
        headerLabel,
        hide,
        skip,
        togglePanel,
        stepHref,
    } = useOnboarding();

    useDriverTour();

    let navItems = [
        { label: 'Home', href: '/' },
        { label: 'Accounts', href: '/accounts' },
        { label: 'Categories', href: '/categories' },
        { label: 'Budgets', href: '/budgets' },
        { label: 'Plans', href: '/plans' },
        { label: 'Rules', href: '/rules' },
        { label: 'Orders', href: '/orders' },
        {
            label: 'Reconciliation',
            href: '/reconciliation/unmatched-transactions',
            match: '/reconciliation',
        },
    ];

    let isActive = (item) => {
        let url = currentUrl.value;
        let href = item.match ?? item.href;

        if (href === '/') {
            return url === '/';
        }

        return url === href || url.startsWith(`${href}/`);
    };
</script>

<template>
    <div class="min-h-screen">
        <header class="border-b">
            <div
                class="mx-auto flex max-w-6xl flex-wrap items-center justify-between gap-4 px-8 py-4"
            >
                <nav class="flex flex-wrap items-center gap-4 text-sm">
                    <Link
                        v-for="item in navItems"
                        :key="item.href"
                        :href="item.href"
                        class="rounded px-1 py-0.5"
                        :class="
                            isActive(item)
                                ? 'font-semibold text-neutral-900'
                                : 'text-neutral-600 hover:text-neutral-900'
                        "
                    >
                        {{ item.label }}
                    </Link>
                </nav>

                <div class="flex items-center gap-3 text-sm">
                    <button
                        v-if="isPresent"
                        type="button"
                        class="rounded border px-3 py-1.5"
                        :aria-expanded="isVisible && panelOpen"
                        :aria-controls="
                            isVisible ? 'onboarding-checklist' : undefined
                        "
                        @click="togglePanel"
                    >
                        {{ headerLabel }}
                    </button>
                    <span class="text-neutral-600">{{ user?.email }}</span>
                    <Link
                        href="/logout"
                        method="post"
                        as="button"
                        class="rounded border px-3 py-1.5"
                    >
                        Log out
                    </Link>
                </div>
            </div>
        </header>

        <main class="mx-auto max-w-6xl space-y-6 p-8">
            <div
                v-if="isVisible && panelOpen"
                id="onboarding-checklist"
            >
                <OnboardingChecklist
                    :steps="steps"
                    :step-href="stepHref"
                    @hide="hide"
                    @skip="skip"
                />
            </div>
            <slot />
        </main>
    </div>
</template>
