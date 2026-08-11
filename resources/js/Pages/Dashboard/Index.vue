<script setup>
    import AuthenticatedLayout from '../../Layouts/AuthenticatedLayout.vue';
    import { computed } from 'vue';

    defineOptions({ layout: AuthenticatedLayout });

    let props = defineProps({
        categories: {
            type: Array,
            required: true,
        },
        uncategorized_amount: {
            type: Number,
            required: true,
        },
        uncategorized_percent: {
            type: Number,
            default: null,
        },
        total_spend: {
            type: Number,
            required: true,
        },
        breakdown: {
            type: Object,
            required: true,
        },
        coverage: {
            type: Object,
            required: true,
        },
    });

    let kindLabel = (kind) => {
        return kind === 'bill' ? 'Bill' : 'Expense';
    };

    let formatMoney = (amount) => {
        let value = Number(amount);

        return value.toLocaleString('en-US', {
            style: 'currency',
            currency: 'USD',
        });
    };

    let formatPercent = (value) => {
        if (value === null || value === undefined) {
            return '—';
        }

        return `${Number(value).toFixed(1)}%`;
    };

    let formatDate = (value) => {
        if (!value) {
            return '—';
        }

        return new Date(`${value}T00:00:00`).toLocaleDateString('en-US', {
            month: 'short',
            day: 'numeric',
            year: 'numeric',
        });
    };

    let formatRange = (from, to) => {
        if (!from && !to) {
            return null;
        }

        if (from && to && from === to) {
            return formatDate(from);
        }

        return `${formatDate(from)} → ${formatDate(to)}`;
    };

    let hasSpend = computed(() => Number(props.total_spend) > 0);

    let overallRange = computed(() =>
        formatRange(props.coverage.from, props.coverage.to),
    );

    let bankRange = computed(() =>
        formatRange(props.coverage.bank_from, props.coverage.bank_to),
    );

    let ordersRange = computed(() =>
        formatRange(props.coverage.orders_from, props.coverage.orders_to),
    );
</script>

<template>
    <div class="space-y-6">
        <div>
            <h1 class="text-2xl font-semibold">Home</h1>
            <p class="text-sm text-neutral-600">
                Spend by category. Reimbursement-group transactions are
                excluded.
            </p>
        </div>

        <div class="space-y-3 rounded border px-4 py-3">
            <div class="flex flex-wrap items-baseline justify-between gap-2">
                <p class="text-sm text-neutral-600">Total spend</p>
                <p class="text-xl font-semibold">
                    {{ formatMoney(total_spend) }}
                </p>
            </div>

            <div
                class="grid gap-2 text-sm sm:grid-cols-3"
            >
                <div class="rounded border px-3 py-2">
                    <p class="text-neutral-600">Bills</p>
                    <p class="font-medium tabular-nums">
                        {{ formatMoney(breakdown.bills.amount) }}
                        <span class="text-neutral-600">
                            ({{ formatPercent(breakdown.bills.percent) }})
                        </span>
                    </p>
                </div>
                <div class="rounded border px-3 py-2">
                    <p class="text-neutral-600">Expenses</p>
                    <p class="font-medium tabular-nums">
                        {{ formatMoney(breakdown.expenses.amount) }}
                        <span class="text-neutral-600">
                            ({{ formatPercent(breakdown.expenses.percent) }})
                        </span>
                    </p>
                </div>
                <div class="rounded border px-3 py-2">
                    <p class="text-neutral-600">Uncategorized</p>
                    <p class="font-medium tabular-nums">
                        {{ formatMoney(breakdown.uncategorized.amount) }}
                        <span class="text-neutral-600">
                            ({{
                                formatPercent(breakdown.uncategorized.percent)
                            }})
                        </span>
                    </p>
                </div>
            </div>

            <div v-if="overallRange" class="text-sm text-neutral-600">
                <p>
                    Covering
                    <span class="font-medium text-neutral-800">{{
                        overallRange
                    }}</span>
                </p>
                <p v-if="bankRange || ordersRange" class="mt-1">
                    <span v-if="bankRange">Bank {{ bankRange }}</span>
                    <span v-if="bankRange && ordersRange"> · </span>
                    <span v-if="ordersRange">Orders {{ ordersRange }}</span>
                </p>
            </div>
            <p v-else class="text-sm text-neutral-600">
                No dated bank transactions or orders yet.
            </p>
        </div>

        <div
            v-if="!hasSpend && categories.length === 0"
            class="text-sm text-neutral-600"
        >
            No spend yet. Import bank transactions and categorize bills and
            expenses to see totals here.
        </div>

        <ul v-else class="divide-y rounded border">
            <li
                v-for="category in categories"
                :key="category.id"
                class="flex flex-wrap items-center justify-between gap-3 px-4 py-3"
            >
                <div>
                    <p class="font-medium">{{ category.name }}</p>
                    <p class="text-sm text-neutral-600">
                        {{ kindLabel(category.kind) }}
                    </p>
                </div>
                <div class="text-right">
                    <p class="font-medium tabular-nums">
                        {{ formatMoney(category.amount) }}
                    </p>
                    <p class="text-sm text-neutral-600 tabular-nums">
                        {{ formatPercent(category.percent) }} of
                        {{ kindLabel(category.kind).toLowerCase() }}s
                    </p>
                </div>
            </li>
            <li
                class="flex flex-wrap items-center justify-between gap-3 px-4 py-3"
            >
                <div>
                    <p class="font-medium">Uncategorized</p>
                    <p class="text-sm text-neutral-600">
                        Bills and expenses without a category
                    </p>
                </div>
                <div class="text-right">
                    <p class="font-medium tabular-nums">
                        {{ formatMoney(uncategorized_amount) }}
                    </p>
                    <p class="text-sm text-neutral-600 tabular-nums">
                        {{ formatPercent(uncategorized_percent) }} of total
                    </p>
                </div>
            </li>
        </ul>
    </div>
</template>
