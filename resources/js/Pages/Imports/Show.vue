<script setup>
    import AuthenticatedLayout from '../../Layouts/AuthenticatedLayout.vue';
    import { Link, router, usePage } from '@inertiajs/vue3';
    import { computed, onMounted, onUnmounted, ref } from 'vue';

    defineOptions({ layout: AuthenticatedLayout });

    let props = defineProps({
        batch: {
            type: Object,
            required: true,
        },
        breadcrumbs: {
            type: Array,
            required: true,
        },
        can_revert: {
            type: Boolean,
            default: false,
        },
        revert_url: {
            type: String,
            default: null,
        },
        date_range: {
            type: Object,
            default: null,
        },
        transactions: {
            type: Array,
            default: null,
        },
        orders: {
            type: Array,
            default: null,
        },
        activities: {
            type: Array,
            default: null,
        },
        pagination: {
            type: Object,
            default: null,
        },
    });

    let page = usePage();
    let flashSuccess = computed(() => page.props.flash?.success);
    let flashError = computed(() => page.props.flash?.error);
    let isInProgress = computed(() =>
        ['pending', 'processing'].includes(props.batch.status),
    );
    let dateRange = computed(
        () => props.date_range ?? props.batch.date_range ?? null,
    );
    let hasDateRange = computed(
        () => Boolean(dateRange.value?.min || dateRange.value?.max),
    );
    let reverting = ref(false);
    let pollId = null;

    const resolvedClassifications = [
        'income',
        'bill',
        'expense',
        'reimbursement',
    ];

    const statusBadgeStyles = {
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

    const classificationStyles = {
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

    let formatDate = (value) => value || '—';

    let formatImportedAt = (value) => {
        if (!value) {
            return '—';
        }

        let date = new Date(value);

        if (Number.isNaN(date.getTime())) {
            return value;
        }

        return date.toLocaleString();
    };

    let formatMoney = (amount) => {
        return new Intl.NumberFormat('en-US', {
            style: 'currency',
            currency: 'USD',
        }).format(amount);
    };

    let isResolvedClassification = (classification) =>
        resolvedClassifications.includes(classification);

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

        return 'border-l-neutral-400';
    };

    let statusBadge = (transaction) => {
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

    let pageHref = (pageNumber) => {
        let url = new URL(window.location.href);
        if (pageNumber > 1) {
            url.searchParams.set('page', String(pageNumber));
        } else {
            url.searchParams.delete('page');
        }
        return `${url.pathname}${url.search}`;
    };

    let revertBatch = () => {
        if (!props.can_revert || !props.revert_url) {
            return;
        }

        if (
            !window.confirm(
                `Revert import "${props.batch.original_filename}"? This removes the imported records and undoes their matches.`,
            )
        ) {
            return;
        }

        reverting.value = true;
        router.delete(props.revert_url, {
            onFinish: () => {
                reverting.value = false;
            },
        });
    };

    onMounted(() => {
        if (!isInProgress.value) {
            return;
        }

        pollId = window.setInterval(() => {
            router.reload({
                only: [
                    'batch',
                    'can_revert',
                    'revert_url',
                    'date_range',
                    'transactions',
                    'orders',
                    'activities',
                    'pagination',
                ],
                onSuccess: (page) => {
                    let status = page.props.batch?.status;
                    if (
                        status &&
                        !['pending', 'processing'].includes(status) &&
                        pollId
                    ) {
                        window.clearInterval(pollId);
                        pollId = null;
                    }
                },
            });
        }, 2000);
    });

    onUnmounted(() => {
        if (pollId) {
            window.clearInterval(pollId);
        }
    });
</script>

<template>
    <div class="space-y-6">
        <div>
            <p class="text-sm text-neutral-600">
                <template
                    v-for="(crumb, index) in breadcrumbs"
                    :key="`${crumb.label}-${index}`"
                >
                    <span v-if="index > 0"> / </span>
                    <Link
                        v-if="crumb.href"
                        :href="crumb.href"
                        class="underline"
                        >{{ crumb.label }}</Link
                    >
                    <template v-else>{{ crumb.label }}</template>
                </template>
            </p>
            <h1 class="mt-2 text-2xl font-semibold">Import batch</h1>
            <p class="text-sm text-neutral-600">
                {{ batch.original_filename }}
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

        <dl class="space-y-3 rounded border p-4 text-sm">
            <div class="flex justify-between gap-4">
                <dt class="text-neutral-600">Imported</dt>
                <dd>{{ formatImportedAt(batch.created_at) }}</dd>
            </div>
            <div class="flex justify-between gap-4">
                <dt class="text-neutral-600">Source</dt>
                <dd>{{ batch.source }}</dd>
            </div>
            <div class="flex justify-between gap-4">
                <dt class="text-neutral-600">Type</dt>
                <dd>{{ batch.type }}</dd>
            </div>
            <div class="flex justify-between gap-4">
                <dt class="text-neutral-600">Status</dt>
                <dd>{{ batch.status }}</dd>
            </div>
            <div class="flex justify-between gap-4">
                <dt class="text-neutral-600">Records</dt>
                <dd>{{ batch.record_count }}</dd>
            </div>
            <div class="flex justify-between gap-4">
                <dt class="text-neutral-600">Date range</dt>
                <dd>
                    <template v-if="hasDateRange">
                        {{ formatDate(dateRange.min) }} → {{ formatDate(dateRange.max) }}
                        <span
                            v-if="dateRange.span_days !== null && dateRange.span_days !== undefined"
                            class="text-neutral-500"
                        >
                            ({{ dateRange.span_days }} day span)
                        </span>
                    </template>
                    <template v-else>—</template>
                </dd>
            </div>
            <div v-if="batch.error_message" class="space-y-1">
                <dt class="text-neutral-600">Error</dt>
                <dd class="text-red-600">{{ batch.error_message }}</dd>
            </div>
        </dl>

        <p v-if="isInProgress" class="text-sm text-neutral-600">
            Processing… this page refreshes automatically.
        </p>

        <div v-if="can_revert && revert_url" class="space-y-2">
            <button
                type="button"
                class="btn rounded border px-4 text-sm text-red-700 hover:bg-red-50"
                :disabled="reverting"
                @click="revertBatch"
            >
                Revert import
            </button>
            <p class="text-sm text-neutral-600">
                Removes the records created by this import and undoes their
                matches.
            </p>
        </div>

        <!-- Orders list for order imports -->
        <section v-if="orders" class="space-y-3 pt-2">
            <div class="flex flex-wrap items-baseline justify-between gap-2">
                <div>
                    <h2 class="text-lg font-semibold">Orders covered</h2>
                    <p class="text-sm text-neutral-600">
                        {{ pagination?.total ?? orders.length }} {{ (pagination?.total ?? orders.length) === 1 ? 'order' : 'orders' }} in this import batch.
                    </p>
                </div>
                <div v-if="hasDateRange" class="text-sm text-neutral-600">
                    {{ formatDate(dateRange.min) }} → {{ formatDate(dateRange.max) }}
                    <template v-if="dateRange.span_days !== null && dateRange.span_days !== undefined">
                        ({{ dateRange.span_days }} day span)
                    </template>
                </div>
            </div>

            <div v-if="orders.length === 0" class="text-sm text-neutral-600">
                <template v-if="isInProgress">
                    Orders will appear once the import finishes processing.
                </template>
                <template v-else>
                    No orders recorded for this import batch.
                </template>
            </div>

            <ul v-else class="divide-y rounded border text-sm">
                <li v-for="order in orders" :key="order.id" class="flex items-stretch">
                    <Link
                        :href="order.detail_url"
                        class="flex min-w-0 flex-1 items-start justify-between gap-4 px-4 py-3 hover:bg-neutral-50 transition-colors"
                    >
                        <div class="min-w-0">
                            <div class="flex flex-wrap items-center gap-2">
                                <p class="font-medium text-brand underline underline-offset-2">
                                    {{ order.order_number }}
                                </p>
                                <span
                                    v-if="order.status"
                                    class="inline-flex items-center rounded px-1.5 py-0.5 text-xs font-medium bg-neutral-100 text-neutral-700"
                                >
                                    {{ order.status }}
                                </span>
                            </div>
                            <p class="mt-1 text-neutral-600">
                                Ordered {{ formatDate(order.ordered_at) }}
                                <template v-if="order.delivered_at">
                                    · Delivered {{ formatDate(order.delivered_at) }}
                                </template>
                                <template v-if="order.payment_last_four">
                                    · •••• {{ order.payment_last_four }}
                                </template>
                                <template v-if="order.items_count">
                                    · {{ order.items_count }} {{ order.items_count === 1 ? 'item' : 'items' }}
                                </template>
                            </p>
                        </div>
                        <div class="shrink-0 text-right">
                            <p class="font-medium">{{ formatMoney(order.total) }}</p>
                            <span class="mt-1 inline-block text-xs text-brand underline">
                                View full order →
                            </span>
                        </div>
                    </Link>
                </li>
            </ul>

            <!-- Pagination -->
            <div
                v-if="pagination && pagination.total > 0 && pagination.last_page > 1"
                class="flex flex-wrap items-center justify-between gap-3 text-sm pt-2"
            >
                <p class="text-neutral-600">
                    Showing {{ pagination.first_item }}–{{ pagination.last_item }}
                    of {{ pagination.total }}
                </p>
                <nav class="flex gap-2" aria-label="Pagination">
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
        </section>

        <!-- Transactions list for bank transaction imports -->
        <section v-if="transactions" class="space-y-3 pt-2">
            <div class="flex flex-wrap items-baseline justify-between gap-2">
                <div>
                    <h2 class="text-lg font-semibold">Transactions covered</h2>
                    <p class="text-sm text-neutral-600">
                        {{ pagination?.total ?? transactions.length }} {{ (pagination?.total ?? transactions.length) === 1 ? 'transaction' : 'transactions' }} in this import batch.
                    </p>
                </div>
                <div v-if="hasDateRange" class="text-sm text-neutral-600">
                    {{ formatDate(dateRange.min) }} → {{ formatDate(dateRange.max) }}
                    <template v-if="dateRange.span_days !== null && dateRange.span_days !== undefined">
                        ({{ dateRange.span_days }} day span)
                    </template>
                </div>
            </div>

            <div v-if="transactions.length === 0" class="text-sm text-neutral-600">
                <template v-if="isInProgress">
                    Transactions will appear once the import finishes processing.
                </template>
                <template v-else>
                    No transactions recorded for this import batch.
                </template>
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
                                :class="classificationStyle(transaction.classification).badge"
                                :title="
                                    transaction.classification_source
                                        ? `Source: ${classificationSourceLabel(
                                              transaction.classification_source,
                                          )}`
                                        : undefined
                                "
                            >
                                {{ classificationStyle(transaction.classification).label }}
                            </span>
                            <span
                                v-if="transaction.category"
                                class="inline-flex items-center rounded px-1.5 py-0.5 text-xs font-medium bg-neutral-100 text-neutral-700"
                            >
                                {{ transaction.category.name }}
                            </span>
                        </div>
                        <p class="mt-1 text-neutral-600">
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

            <!-- Pagination -->
            <div
                v-if="pagination && pagination.total > 0 && pagination.last_page > 1"
                class="flex flex-wrap items-center justify-between gap-3 text-sm pt-2"
            >
                <p class="text-neutral-600">
                    Showing {{ pagination.first_item }}–{{ pagination.last_item }}
                    of {{ pagination.total }}
                </p>
                <nav class="flex gap-2" aria-label="Pagination">
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
        </section>

        <!-- Venmo activities list -->
        <section v-if="activities" class="space-y-3 pt-2">
            <div class="flex flex-wrap items-baseline justify-between gap-2">
                <div>
                    <h2 class="text-lg font-semibold">Venmo activities covered</h2>
                    <p class="text-sm text-neutral-600">
                        {{ pagination?.total ?? activities.length }} {{ (pagination?.total ?? activities.length) === 1 ? 'activity' : 'activities' }} in this import batch.
                    </p>
                </div>
                <div v-if="hasDateRange" class="text-sm text-neutral-600">
                    {{ formatDate(dateRange.min) }} → {{ formatDate(dateRange.max) }}
                    <template v-if="dateRange.span_days !== null && dateRange.span_days !== undefined">
                        ({{ dateRange.span_days }} day span)
                    </template>
                </div>
            </div>

            <div v-if="activities.length === 0" class="text-sm text-neutral-600">
                <template v-if="isInProgress">
                    Activities will appear once the import finishes processing.
                </template>
                <template v-else>
                    No activities recorded for this import batch.
                </template>
            </div>

            <ul v-else class="divide-y rounded border text-sm">
                <li
                    v-for="activity in activities"
                    :key="activity.id"
                    class="flex items-start justify-between gap-4 px-4 py-3"
                >
                    <div class="min-w-0">
                        <p class="font-medium">
                            {{ activity.note || 'Payment' }}
                        </p>
                        <p class="mt-1 text-neutral-600">
                            {{ formatDate(activity.occurred_at) }}
                            <template v-if="activity.from_name && activity.to_name">
                                · {{ activity.from_name }} → {{ activity.to_name }}
                            </template>
                            <template v-if="activity.funding_source">
                                · {{ activity.funding_source }}
                                <template v-if="activity.funding_last_four">
                                    (•••• {{ activity.funding_last_four }})
                                </template>
                            </template>
                            <template v-if="activity.status">
                                · {{ activity.status }}
                            </template>
                        </p>
                    </div>
                    <p class="shrink-0 font-medium">
                        {{ formatMoney(activity.amount) }}
                    </p>
                </li>
            </ul>

            <!-- Pagination -->
            <div
                v-if="pagination && pagination.total > 0 && pagination.last_page > 1"
                class="flex flex-wrap items-center justify-between gap-3 text-sm pt-2"
            >
                <p class="text-neutral-600">
                    Showing {{ pagination.first_item }}–{{ pagination.last_item }}
                    of {{ pagination.total }}
                </p>
                <nav class="flex gap-2" aria-label="Pagination">
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
        </section>
    </div>
</template>
