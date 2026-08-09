<script setup>
    import AuthenticatedLayout from '../../Layouts/AuthenticatedLayout.vue';
    import { Link, router } from '@inertiajs/vue3';
    import { ref, watch } from 'vue';

    defineOptions({ layout: AuthenticatedLayout });

    let props = defineProps({
        retailers: {
            type: Array,
            required: true,
        },
        otherMerchants: {
            type: Array,
            required: true,
        },
        bankCoverage: {
            type: Object,
            default: null,
        },
        filters: {
            type: Object,
            required: true,
        },
    });

    let search = ref(props.filters.q ?? '');

    watch(
        () => props.filters.q,
        (value) => {
            search.value = value ?? '';
        },
    );

    let submitSearch = () => {
        router.get(
            '/orders',
            { q: search.value || undefined },
            {
                preserveState: true,
                replace: true,
            },
        );
    };

    let formatDate = (value) => {
        if (!value) {
            return '—';
        }

        return value;
    };
</script>

<template>
    <div class="space-y-8">
        <div>
            <h1 class="text-2xl font-semibold">Orders</h1>
            <p class="text-sm text-neutral-600">
                Browse retailer order history, or search bank activity for other
                merchants.
            </p>
        </div>

        <div v-if="bankCoverage" class="rounded border px-4 py-3 text-sm">
            <p class="font-medium">All bank transactions</p>
            <p class="text-neutral-600">
                {{ formatDate(bankCoverage.min) }} →
                {{ formatDate(bankCoverage.max) }}
            </p>
            <p class="mt-1 text-neutral-600">
                Multi-transaction matches skip orders within 3 days of these
                edges.
            </p>
        </div>
        <p v-else class="text-sm text-neutral-600">
            No bank transactions imported yet.
        </p>

        <section class="space-y-3">
            <div>
                <h2 class="text-lg font-semibold">Retailers</h2>
                <p class="text-sm text-neutral-600">
                    Imported order history from Walmart and Amazon.
                </p>
            </div>

            <ul class="divide-y rounded border">
                <li
                    v-for="retailer in retailers"
                    :key="retailer.normalized_name"
                >
                    <Link
                        :href="`/orders/${retailer.normalized_name}`"
                        class="block px-4 py-3 hover:bg-neutral-50"
                    >
                        <div class="flex items-start justify-between gap-4">
                            <div>
                                <p class="font-medium">{{ retailer.name }}</p>
                                <p class="text-sm text-neutral-600">
                                    Retailer · Imported order history
                                </p>
                            </div>
                            <div class="text-right text-sm">
                                <p>{{ retailer.order_count }} orders</p>
                                <p class="text-neutral-600">
                                    {{ formatDate(retailer.min_ordered_at) }} →
                                    {{ formatDate(retailer.max_ordered_at) }}
                                </p>
                                <p
                                    v-if="retailer.coverage_span_days !== null"
                                    class="text-neutral-600"
                                >
                                    {{ retailer.coverage_span_days }} day span
                                </p>
                            </div>
                        </div>
                    </Link>
                </li>
            </ul>
        </section>

        <section class="space-y-3">
            <div>
                <h2 class="text-lg font-semibold">Other merchants</h2>
                <p class="text-sm text-neutral-600">
                    Merchants matched from bank transactions (excluding order
                    retailers).
                </p>
            </div>

            <form class="flex flex-wrap gap-2" @submit.prevent="submitSearch">
                <input
                    v-model="search"
                    type="search"
                    placeholder="Search merchant name or type"
                    class="min-w-64 flex-1 rounded border px-3 py-2 text-sm"
                />
                <button type="submit" class="rounded border px-4 py-2 text-sm">
                    Search
                </button>
            </form>

            <div
                v-if="otherMerchants.length === 0"
                class="text-sm text-neutral-600"
            >
                No other merchants match this search.
            </div>

            <ul v-else class="divide-y rounded border">
                <li v-for="merchant in otherMerchants" :key="merchant.id">
                    <Link
                        :href="`/merchants/${merchant.id}`"
                        class="block px-4 py-3 hover:bg-neutral-50"
                    >
                        <div class="flex items-start justify-between gap-4">
                            <div>
                                <p class="font-medium">{{ merchant.name }}</p>
                                <p class="text-sm capitalize text-neutral-600">
                                    {{ merchant.type }}
                                </p>
                            </div>
                            <div class="text-right text-sm">
                                <p>{{ merchant.transaction_count }} txs</p>
                                <p class="text-neutral-600">
                                    {{ formatDate(merchant.min_posted_at) }} →
                                    {{ formatDate(merchant.max_posted_at) }}
                                </p>
                                <p
                                    v-if="merchant.coverage_span_days !== null"
                                    class="text-neutral-600"
                                >
                                    {{ merchant.coverage_span_days }} day span
                                </p>
                            </div>
                        </div>
                    </Link>
                </li>
            </ul>
        </section>
    </div>
</template>
