<script setup>
    import AuthenticatedLayout from '../../Layouts/AuthenticatedLayout.vue';
    import { Link, router, usePage } from '@inertiajs/vue3';
    import { computed, ref, watch } from 'vue';

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

    let page = usePage();
    let flashSuccess = computed(() => page.props.flash?.success);

    let search = ref(props.filters.q ?? '');
    let selectedIds = ref([]);
    let selectedNames = ref({});

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

    let isSelected = (id) => selectedIds.value.includes(id);

    let toggleSelected = (merchant) => {
        if (isSelected(merchant.id)) {
            selectedIds.value = selectedIds.value.filter(
                (id) => id !== merchant.id,
            );

            return;
        }

        selectedIds.value = [...selectedIds.value, merchant.id];
        selectedNames.value = {
            ...selectedNames.value,
            [merchant.id]: merchant.name,
        };
    };

    let survivor = computed(() => {
        if (selectedIds.value.length < 2) {
            return null;
        }

        let id = Math.min(...selectedIds.value);

        return {
            id,
            name: selectedNames.value[id] ?? 'the oldest merchant',
        };
    });

    let submitMerge = () => {
        if (!survivor.value) {
            return;
        }

        let count = selectedIds.value.length;
        let name = survivor.value.name;

        if (
            !window.confirm(
                `Merge ${count} merchants into ${name}? Matching rules and related records will move, and the other merchants will be removed.`,
            )
        ) {
            return;
        }

        router.post(
            '/merchants/merge',
            { merchant_ids: selectedIds.value },
            {
                onSuccess: () => {
                    selectedIds.value = [];
                    selectedNames.value = {};
                },
            },
        );
    };
</script>

<template>
    <div class="space-y-8">
        <div>
            <div class="flex flex-wrap items-start justify-between gap-4">
                <div>
                    <h1 class="text-2xl font-semibold">Orders</h1>
                    <p class="text-sm text-neutral-600">
                        Browse retailer order history, or search bank activity
                        for other merchants.
                    </p>
                </div>
                <Link
                    href="/orders/categorize"
                    class="btn rounded border px-4 text-sm"
                >
                    Categorize order lines
                </Link>
            </div>
        </div>

        <p
            v-if="flashSuccess"
            class="rounded border border-green-200 bg-green-50 px-3 py-2 text-sm text-green-800"
        >
            {{ flashSuccess }}
        </p>

        <div v-if="bankCoverage" class="rounded border px-4 py-3 text-sm">
            <p class="font-medium">All bank transactions</p>
            <p class="text-neutral-600">
                {{ formatDate(bankCoverage.min) }} →
                {{ formatDate(bankCoverage.max) }}
            </p>
            <p class="mt-1 text-neutral-600">
                Multi-transaction matches skip orders outside this posted-date
                range.
            </p>
        </div>
        <p v-else class="text-sm text-neutral-600">
            No bank transactions imported yet.
        </p>

        <section class="space-y-3" data-tour="import-orders-retailers">
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
                    class="flex items-stretch"
                >
                    <Link
                        :href="`/orders/${retailer.normalized_name}`"
                        class="min-w-0 flex-1 px-4 py-3 hover:bg-neutral-50"
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
                    <Link
                        :href="`/orders/${retailer.normalized_name}/imports`"
                        class="shrink-0 self-center px-4 py-3 text-sm text-neutral-700 underline hover:text-neutral-900"
                        data-tour="import-orders-imports-link"
                    >
                        Imports
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
                    class="min-w-64 flex-1 rounded border px-3 text-sm"
                />
                <button type="submit" class="btn rounded border px-4 text-sm">
                    Search
                </button>
                <button
                    v-if="survivor"
                    type="button"
                    class="btn rounded border px-4 text-sm"
                    @click="submitMerge"
                >
                    Merge {{ selectedIds.length }} merchants into
                    {{ survivor.name }}
                </button>
            </form>

            <div
                v-if="otherMerchants.length === 0"
                class="text-sm text-neutral-600"
            >
                No other merchants match this search.
            </div>

            <ul v-else class="divide-y rounded border">
                <li
                    v-for="merchant in otherMerchants"
                    :key="merchant.id"
                    class="flex items-stretch"
                >
                    <label class="flex shrink-0 items-center px-3">
                        <input
                            type="checkbox"
                            class="mt-0.5"
                            :checked="isSelected(merchant.id)"
                            @change="toggleSelected(merchant)"
                        />
                        <span class="sr-only">Select {{ merchant.name }}</span>
                    </label>
                    <Link
                        :href="`/merchants/${merchant.id}`"
                        class="min-w-0 flex-1 px-4 py-3 hover:bg-neutral-50"
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
