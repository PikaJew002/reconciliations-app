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
        pagination: {
            type: Object,
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
    let fromDate = ref(props.filters.from ?? '');
    let toDate = ref(props.filters.to ?? '');

    watch(
        () => props.filters.q,
        (value) => {
            search.value = value ?? '';
        },
    );

    watch(
        () => props.filters.from,
        (value) => {
            fromDate.value = value ?? '';
        },
    );

    watch(
        () => props.filters.to,
        (value) => {
            toDate.value = value ?? '';
        },
    );

    let hasFilters = computed(
        () =>
            Boolean(props.filters.q) ||
            Boolean(props.filters.from) ||
            Boolean(props.filters.to),
    );

    let queryParams = (overrides = {}) => {
        let query = {
            q: search.value || undefined,
            from: fromDate.value || undefined,
            to: toDate.value || undefined,
            ...overrides,
        };

        Object.keys(query).forEach((key) => {
            if (
                query[key] === '' ||
                query[key] === null ||
                query[key] === undefined
            ) {
                delete query[key];
            }
        });

        if (query.page === 1) {
            delete query.page;
        }

        return query;
    };

    let applyFilters = () => {
        router.get(
            `/orders/${props.merchant.normalized_name}`,
            queryParams(),
            {
                preserveState: true,
                replace: true,
            },
        );
    };

    let clearFilters = () => {
        search.value = '';
        fromDate.value = '';
        toDate.value = '';

        router.get(
            `/orders/${props.merchant.normalized_name}`,
            {},
            {
                preserveState: true,
                replace: true,
            },
        );
    };

    let pageHref = (page) => {
        let params = new URLSearchParams();

        if (props.filters.q) {
            params.set('q', props.filters.q);
        }

        if (props.filters.from) {
            params.set('from', props.filters.from);
        }

        if (props.filters.to) {
            params.set('to', props.filters.to);
        }

        if (page > 1) {
            params.set('page', String(page));
        }

        let qs = params.toString();

        return `/orders/${props.merchant.normalized_name}${qs ? `?${qs}` : ''}`;
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
                <p v-if="merchant.order_count !== undefined" class="mt-1 text-neutral-600">
                    {{ merchant.order_count }} orders
                    <template v-if="hasFilters">
                        · {{ pagination.total }} match these filters
                    </template>
                </p>
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
            Some orders in this range are more than 10 days before bank posted
            coverage, or after it ends, so multi-transaction matching may skip
            them.
            <Link href="/accounts" class="underline">
                Import more bank history
            </Link>
        </p>

        <form
            class="flex flex-wrap items-end gap-2"
            @submit.prevent="applyFilters"
        >
            <label class="min-w-64 flex-1 text-sm">
                <span class="mb-1 block text-neutral-600">Search</span>
                <input
                    v-model="search"
                    type="search"
                    placeholder="Search order number or total"
                    class="w-full rounded border px-3 text-sm"
                />
            </label>
            <label class="text-sm">
                <span class="mb-1 block text-neutral-600">From</span>
                <input
                    v-model="fromDate"
                    type="date"
                    class="rounded border px-3 text-sm"
                    :min="merchant.min_ordered_at ?? undefined"
                    :max="toDate || merchant.max_ordered_at || undefined"
                />
            </label>
            <label class="text-sm">
                <span class="mb-1 block text-neutral-600">To</span>
                <input
                    v-model="toDate"
                    type="date"
                    class="rounded border px-3 text-sm"
                    :min="fromDate || merchant.min_ordered_at || undefined"
                    :max="merchant.max_ordered_at ?? undefined"
                />
            </label>
            <button type="submit" class="btn rounded border px-4 text-sm">
                Apply
            </button>
            <button
                v-if="hasFilters"
                type="button"
                class="btn rounded border px-4 text-sm text-neutral-700"
                @click="clearFilters"
            >
                Clear
            </button>
        </form>

        <div v-if="orders.length === 0" class="text-sm text-neutral-600">
            <template v-if="hasFilters">
                No orders match these filters.
            </template>
            <template v-else> No orders imported yet. </template>
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
                            Outside bank posted coverage — multi-transaction matching skipped
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

        <div
            v-if="pagination.total > 0"
            class="flex flex-wrap items-center justify-between gap-3 text-sm"
        >
            <p class="text-neutral-600">
                Showing {{ pagination.first_item }}–{{ pagination.last_item }}
                of {{ pagination.total }}
            </p>
            <nav
                v-if="pagination.last_page > 1"
                class="flex gap-2"
                aria-label="Pagination"
            >
                <Link
                    :href="pageHref(pagination.current_page - 1)"
                    class="btn rounded border px-4 text-sm"
                    :class="{
                        'pointer-events-none opacity-40':
                            pagination.current_page <= 1,
                    }"
                    :tabindex="pagination.current_page <= 1 ? -1 : undefined"
                    :aria-disabled="pagination.current_page <= 1"
                    preserve-scroll
                    preserve-state
                >
                    Previous
                </Link>
                <Link
                    :href="pageHref(pagination.current_page + 1)"
                    class="btn rounded border px-4 text-sm"
                    :class="{
                        'pointer-events-none opacity-40':
                            pagination.current_page >= pagination.last_page,
                    }"
                    :tabindex="
                        pagination.current_page >= pagination.last_page
                            ? -1
                            : undefined
                    "
                    :aria-disabled="
                        pagination.current_page >= pagination.last_page
                    "
                    preserve-scroll
                    preserve-state
                >
                    Next
                </Link>
            </nav>
        </div>
    </div>
</template>
