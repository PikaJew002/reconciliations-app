<script setup>
    import { Link, usePage } from '@inertiajs/vue3';
    import { computed } from 'vue';

    defineProps({
        activeTab: {
            type: String,
            required: true,
        },
    });

    let page = usePage();
    let flashSuccess = computed(() => page.props.flash?.success);
    let flashError = computed(() => page.props.flash?.error);

    let tabs = [
        {
            id: 'pending-spend',
            href: '/api-tokens/pending-spend',
            label: 'Pending spend',
        },
        {
            id: 'leftover-reporting',
            href: '/api-tokens/leftover-reporting',
            label: 'Leftover reporting',
        },
        {
            id: 'retailer-scraper',
            href: '/api-tokens/retailer-scraper',
            label: 'Retailer scraper',
        },
    ];
</script>

<template>
    <div class="space-y-8">
        <div>
            <h1 class="text-2xl font-semibold">API tokens</h1>
            <p class="text-sm text-neutral-600">
                Mint a token for pending spend, leftover reporting, or the
                retailer scraper, then paste it as a Bearer token.
            </p>
        </div>

        <p
            v-if="flashSuccess"
            class="rounded border border-green-200 bg-green-50 px-3 py-2 text-sm text-green-800"
        >
            {{ flashSuccess }}
        </p>
        <p
            v-if="flashError"
            class="rounded border border-red-200 bg-red-50 px-3 py-2 text-sm text-red-800"
        >
            {{ flashError }}
        </p>

        <div class="space-y-4">
            <div class="flex flex-wrap gap-2 border-b pb-2">
                <Link
                    v-for="tab in tabs"
                    :key="tab.id"
                    :href="tab.href"
                    class="rounded px-3 py-1.5 text-sm"
                    :class="
                        activeTab === tab.id
                            ? 'bg-brand hover:bg-brand-hover text-white'
                            : 'text-neutral-700 hover:bg-neutral-100'
                    "
                >
                    {{ tab.label }}
                </Link>
            </div>

            <slot />
        </div>
    </div>
</template>
