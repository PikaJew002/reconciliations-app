<script setup>
    import { Link, usePage } from '@inertiajs/vue3';
    import { computed } from 'vue';

    let page = usePage();
    let user = computed(() => page.props.auth.user);
    let currentUrl = computed(() => page.url.split('?')[0]);

    let navItems = [
        { label: 'Imports', href: '/imports' },
        { label: 'Accounts', href: '/accounts' },
        { label: 'Orders', href: '/orders' },
        { label: 'Reconciliation', href: '/reconciliation' },
    ];

    let isActive = (href) => {
        let url = currentUrl.value;

        if (href === '/imports') {
            return url === '/imports' || url.startsWith('/imports/');
        }

        return url === href || url.startsWith(`${href}/`);
    };
</script>

<template>
    <div class="min-h-screen">
        <header class="border-b">
            <div
                class="mx-auto flex max-w-3xl flex-wrap items-center justify-between gap-4 px-8 py-4"
            >
                <nav class="flex flex-wrap items-center gap-4 text-sm">
                    <Link
                        v-for="item in navItems"
                        :key="item.href"
                        :href="item.href"
                        class="rounded px-1 py-0.5"
                        :class="
                            isActive(item.href)
                                ? 'font-semibold text-neutral-900'
                                : 'text-neutral-600 hover:text-neutral-900'
                        "
                    >
                        {{ item.label }}
                    </Link>
                </nav>

                <div class="flex items-center gap-3 text-sm">
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

        <main class="mx-auto max-w-3xl space-y-6 p-8">
            <slot />
        </main>
    </div>
</template>
