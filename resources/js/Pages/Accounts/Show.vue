<script setup>
    import AuthenticatedLayout from '../../Layouts/AuthenticatedLayout.vue';
    import { Link, router } from '@inertiajs/vue3';
    import { computed, ref, watch } from 'vue';

    defineOptions({ layout: AuthenticatedLayout });

    let props = defineProps({
        account: {
            type: Object,
            required: true,
        },
        transactions: {
            type: Array,
            required: true,
        },
        pagination: {
            type: Object,
            required: true,
        },
        filters: {
            type: Object,
            required: true,
        },
    });

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
        router.get(`/accounts/${props.account.id}`, queryParams(), {
            preserveState: true,
            replace: true,
        });
    };

    let clearFilters = () => {
        search.value = '';
        fromDate.value = '';
        toDate.value = '';

        router.get(
            `/accounts/${props.account.id}`,
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

        return `/accounts/${props.account.id}${qs ? `?${qs}` : ''}`;
    };

    let formatDate = (value) => value || '—';

    let formatMoney = (amount) => {
        return new Intl.NumberFormat('en-US', {
            style: 'currency',
            currency: 'USD',
        }).format(amount);
    };

    let resolvedClassifications = [
        'income',
        'bill',
        'expense',
        'reimbursement',
    ];

    let statusBadgeStyles = {
        matched: {
            badge: 'bg-blue-50 text-blue-900',
            dot: 'bg-blue-600',
            label: 'Matched',
        },
        unmatched: {
            badge: 'bg-amber-50 text-amber-900',
            dot: 'bg-amber-500',
            label: 'Unmatched',
        },
        partial: {
            badge: 'bg-sky-50 text-sky-900',
            dot: 'bg-sky-500',
            label: 'Partial',
        },
        ignored: {
            badge: 'bg-neutral-100 text-neutral-700',
            dot: 'bg-neutral-500',
            label: 'Ignored',
        },
    };

    let classificationStyles = {
        income: {
            badge: 'bg-emerald-50 text-emerald-900',
            label: 'Income',
        },
        transfer: {
            badge: 'bg-indigo-50 text-indigo-900',
            label: 'Transfer',
        },
        bill: {
            badge: 'bg-violet-50 text-violet-900',
            label: 'Bill',
        },
        expense: {
            badge: 'bg-rose-50 text-rose-900',
            label: 'Expense',
        },
        reimbursement: {
            badge: 'bg-teal-50 text-teal-900',
            label: 'Reimbursement',
        },
    };

    let isResolvedClassification = (classification) =>
        resolvedClassifications.includes(classification);

    // Green = confirmed categorization. Blue = linked to an order (items may still need categories).
    let rowToneClass = (transaction) => {
        if (
            transaction.status === 'ignored' &&
            isResolvedClassification(transaction.classification)
        ) {
            return 'border-l-green-500';
        }

        if (transaction.status === 'matched') {
            return 'border-l-blue-500';
        }

        if (transaction.status === 'unmatched') {
            return 'border-l-amber-500';
        }

        if (transaction.status === 'partial') {
            return 'border-l-sky-500';
        }

        // Transfers and other ignored/inert rows stay gray.
        return 'border-l-neutral-400';
    };

    let statusBadge = (transaction) => {
        // Classification badge already explains these; "Ignored" is misleading.
        if (
            transaction.status === 'ignored' &&
            transaction.classification
        ) {
            return null;
        }

        return (
            statusBadgeStyles[transaction.status] ?? {
                badge: 'bg-neutral-100 text-neutral-700',
                dot: 'bg-neutral-500',
                label: transaction.status,
            }
        );
    };

    let classificationStyle = (classification) => {
        if (!classification) {
            return null;
        }

        return (
            classificationStyles[classification] ?? {
                badge: 'bg-neutral-100 text-neutral-700',
                label: classification,
            }
        );
    };

    let classificationSourceLabel = (source) => {
        return (
            {
                heuristic: 'suggested',
                learned: 'learned',
                paired: 'paired',
                manual: 'manual',
            }[source] ?? source
        );
    };
</script>

<template>
    <div class="space-y-6">
        <div class="flex flex-wrap items-start justify-between gap-4">
            <div>
                <p class="text-sm text-neutral-600">
                    <Link href="/accounts" class="underline">Accounts</Link>
                    /
                    {{ account.name }}
                </p>
                <h1 class="mt-2 text-2xl font-semibold">{{ account.name }}</h1>
                <p class="text-sm text-neutral-600">
                    {{ account.institution_name }}
                    · {{ account.account_type }}
                    <template v-if="account.last_four">
                        · •••• {{ account.last_four }}</template
                    >
                </p>
                <p
                    v-if="account.is_off_book"
                    class="mt-2 max-w-xl text-sm text-neutral-600"
                >
                    Gift cards, store balances, and other payments that did not
                    post to an imported account.
                </p>
            </div>
            <div v-if="!account.is_off_book" class="flex flex-wrap gap-2">
                <Link
                    :href="`/accounts/${account.id}/imports`"
                    class="btn rounded border px-4 text-sm text-neutral-700 hover:bg-neutral-100"
                >
                    Imports
                </Link>
                <Link
                    :href="`/accounts/${account.id}/edit`"
                    class="btn rounded border px-4 text-sm text-neutral-700 hover:bg-neutral-100"
                >
                    Edit account
                </Link>
            </div>
        </div>

        <div class="rounded border px-4 py-3 text-sm">
            <p class="font-medium">Posted-date coverage</p>
            <p class="text-neutral-600">
                {{ formatDate(account.min_posted_at) }} →
                {{ formatDate(account.max_posted_at) }}
                <template v-if="account.coverage_span_days !== null">
                    ({{ account.coverage_span_days }} day span)
                </template>
            </p>
            <p class="mt-1 text-neutral-600">
                {{ account.transaction_count }} transactions
                <template v-if="hasFilters">
                    · {{ pagination.total }} match these filters
                </template>
            </p>
        </div>

        <form
            class="flex flex-wrap items-end gap-2"
            @submit.prevent="applyFilters"
        >
            <label class="min-w-64 flex-1 text-sm">
                <span class="mb-1 block text-neutral-600">Search</span>
                <input
                    v-model="search"
                    type="search"
                    placeholder="Description or amount"
                    class="w-full rounded border px-3 text-sm"
                />
            </label>
            <label class="text-sm">
                <span class="mb-1 block text-neutral-600">From</span>
                <input
                    v-model="fromDate"
                    type="date"
                    class="rounded border px-3 text-sm"
                    :min="account.min_posted_at ?? undefined"
                    :max="toDate || account.max_posted_at || undefined"
                />
            </label>
            <label class="text-sm">
                <span class="mb-1 block text-neutral-600">To</span>
                <input
                    v-model="toDate"
                    type="date"
                    class="rounded border px-3 text-sm"
                    :min="fromDate || account.min_posted_at || undefined"
                    :max="account.max_posted_at ?? undefined"
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

        <div v-if="transactions.length === 0" class="text-sm text-neutral-600">
            <template v-if="hasFilters">
                No transactions match these filters.
            </template>
            <template v-else> No transactions imported yet. </template>
        </div>

        <ul v-else class="divide-y rounded border text-sm">
            <li
                v-for="transaction in transactions"
                :key="transaction.id"
                class="flex items-start justify-between gap-4 border-l-4 px-4 py-3"
                :class="rowToneClass(transaction)"
            >
                <div class="min-w-0">
                    <div class="flex flex-wrap items-center gap-2">
                        <p class="font-medium">{{ transaction.description }}</p>
                        <span
                            v-if="statusBadge(transaction)"
                            class="inline-flex items-center gap-1 rounded px-1.5 py-0.5 text-xs font-medium"
                            :class="statusBadge(transaction).badge"
                        >
                            <span
                                class="size-1.5 rounded-full"
                                :class="statusBadge(transaction).dot"
                            />
                            {{ statusBadge(transaction).label }}
                        </span>
                        <span
                            v-if="classificationStyle(transaction.classification)"
                            class="inline-flex items-center rounded px-1.5 py-0.5 text-xs font-medium"
                            :class="
                                classificationStyle(transaction.classification)
                                    .badge
                            "
                            :title="
                                transaction.classification_source
                                    ? `Source: ${classificationSourceLabel(
                                          transaction.classification_source,
                                      )}`
                                    : undefined
                            "
                        >
                            {{
                                classificationStyle(transaction.classification)
                                    .label
                            }}
                        </span>
                        <span
                            v-if="transaction.category"
                            class="inline-flex items-center rounded px-1.5 py-0.5 text-xs font-medium bg-neutral-100 text-neutral-700"
                        >
                            {{ transaction.category.name }}
                        </span>
                    </div>
                    <p class="text-neutral-600">
                        {{ formatDate(transaction.posted_at) }}
                        <template v-if="transaction.venmo_summary">
                            · {{ transaction.venmo_summary }}
                        </template>
                        <template v-else-if="transaction.merchant">
                            · {{ transaction.merchant.name }}
                        </template>
                        <template v-if="transaction.card_last_four">
                            · •••• {{ transaction.card_last_four }}
                        </template>
                    </p>
                </div>
                <p class="shrink-0 font-medium">
                    {{ formatMoney(transaction.amount) }}
                </p>
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
