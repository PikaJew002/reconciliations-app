<script setup>
    import AuthenticatedLayout from '../../Layouts/AuthenticatedLayout.vue';
    import { Link, router, usePage } from '@inertiajs/vue3';
    import { computed, ref, watch } from 'vue';

    defineOptions({ layout: AuthenticatedLayout });

    let props = defineProps({
        merchant: {
            type: Object,
            required: true,
        },
        orders: {
            type: Array,
            required: true,
        },
        ordersTruncated: {
            type: Boolean,
            required: true,
        },
        orderCoverage: {
            type: Object,
            default: null,
        },
        bankCoverage: {
            type: Object,
            default: null,
        },
        nearImportEdge: {
            type: Boolean,
            required: true,
        },
        filters: {
            type: Object,
            required: true,
        },
    });

    let page = usePage();
    let flashSuccess = computed(() => page.props.flash?.success);
    let search = ref(props.filters.q ?? '');

    watch(
        () => props.filters.q,
        (value) => {
            search.value = value ?? '';
        },
    );

    let submitSearch = () => {
        router.get(
            `/orders/${props.merchant.normalized_name}`,
            {
                q: search.value || undefined,
            },
            {
                preserveState: true,
                replace: true,
            },
        );
    };

    let formatDate = (value) => value || '—';

    let formatMoney = (amount) => {
        return new Intl.NumberFormat('en-US', {
            style: 'currency',
            currency: 'USD',
        }).format(amount);
    };
</script>

<template>
    <div class="space-y-6">
        <div>
            <div class="flex flex-wrap items-start justify-between gap-4">
                <div>
                    <p class="text-sm text-neutral-600">
                        <Link href="/orders" class="underline">Orders</Link>
                        /
                        {{ merchant.name }}
                    </p>
                    <h1 class="text-2xl font-semibold">
                        {{ merchant.name }} orders
                    </h1>
                    <p class="text-sm text-neutral-600">
                        {{ merchant.name }} orders and how their dates sit
                        against bank import coverage.
                    </p>
                </div>
                <Link
                    :href="`/orders/${merchant.normalized_name}/imports`"
                    class="btn rounded border px-4 text-sm text-neutral-700 hover:bg-neutral-100"
                >
                    Imports
                </Link>
            </div>
        </div>

        <p
            v-if="flashSuccess"
            class="rounded border border-green-200 bg-green-50 px-3 py-2 text-sm text-green-800"
        >
            {{ flashSuccess }}
        </p>

        <div class="grid gap-3 sm:grid-cols-2">
            <div class="rounded border px-4 py-3 text-sm">
                <p class="font-medium">Order date coverage</p>
                <p v-if="orderCoverage" class="text-neutral-600">
                    {{ formatDate(orderCoverage.min) }} →
                    {{ formatDate(orderCoverage.max) }}
                </p>
                <p v-else class="text-neutral-600">No orders in this view.</p>
            </div>
            <div class="rounded border px-4 py-3 text-sm">
                <p class="font-medium">Bank posted coverage</p>
                <p v-if="bankCoverage" class="text-neutral-600">
                    {{ formatDate(bankCoverage.min) }} →
                    {{ formatDate(bankCoverage.max) }}
                </p>
                <p v-else class="text-neutral-600">
                    No bank transactions imported.
                </p>
            </div>
        </div>

        <p
            v-if="nearImportEdge"
            class="rounded border border-amber-200 bg-amber-50 px-3 py-2 text-sm text-amber-900"
        >
            Some orders in this range fall outside bank posted coverage,
            so multi-transaction matching may skip them.
            <Link href="/accounts" class="underline">
                Import more bank history
            </Link>
        </p>

        <form class="flex flex-wrap gap-2" @submit.prevent="submitSearch">
            <input
                v-model="search"
                type="search"
                placeholder="Search order number or total"
                class="min-w-64 flex-1 rounded border px-3 text-sm"
            />
            <button type="submit" class="btn rounded border px-4 text-sm">
                Search
            </button>
        </form>

        <div v-if="orders.length === 0" class="text-sm text-neutral-600">
            No orders match this search.
        </div>

        <ul v-else class="divide-y rounded border text-sm">
            <li v-for="order in orders" :key="order.id" class="flex items-stretch">
                <Link
                    :href="`/orders/${merchant.normalized_name}/${order.id}`"
                    class="flex min-w-0 flex-1 items-start justify-between gap-4 px-4 py-3 hover:bg-neutral-50"
                >
                    <div>
                        <p class="font-medium">{{ order.order_number }}</p>
                        <p class="text-neutral-600">
                            Ordered {{ formatDate(order.ordered_at) }} · Delivered
                            {{ formatDate(order.delivered_at) }} ·
                            {{ order.status }}
                            <template v-if="order.payment_last_four">
                                · •••• {{ order.payment_last_four }}
                            </template>
                        </p>
                        <p
                            v-if="order.near_import_edge"
                            class="mt-1 text-amber-800"
                        >
                            Outside bank posted coverage — multi-tx matching skipped
                        </p>
                        <p
                            v-if="!order.components_balanced"
                            class="mt-1 text-amber-800"
                        >
                            Unbalanced — components
                            {{ formatMoney(order.component_sum) }}, gap
                            {{ formatMoney(order.gap) }}
                        </p>
                    </div>
                    <p class="font-medium">{{ formatMoney(order.total) }}</p>
                </Link>
            </li>
        </ul>

        <p v-if="ordersTruncated" class="text-sm text-neutral-600">
            Showing the newest 50 matching orders.
        </p>
    </div>
</template>
