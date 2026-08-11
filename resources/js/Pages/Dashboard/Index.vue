<script setup>
    import AuthenticatedLayout from '../../Layouts/AuthenticatedLayout.vue';
    import { computed } from 'vue';

    defineOptions({ layout: AuthenticatedLayout });

    let props = defineProps({
        total_income: {
            type: Number,
            required: true,
        },
        total_spend: {
            type: Number,
            required: true,
        },
        sections: {
            type: Object,
            required: true,
        },
        coverage: {
            type: Object,
            required: true,
        },
    });

    let kindLabel = (kind) => {
        return (
            {
                bill: 'Bill',
                expense: 'Expense',
                income: 'Income',
            }[kind] ?? kind
        );
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

    let cardStyle = (color) => {
        if (!color) {
            return undefined;
        }

        return {
            borderLeftColor: color,
            backgroundColor: `${color}14`,
        };
    };

    let overallRange = computed(() =>
        formatRange(props.coverage.from, props.coverage.to),
    );

    let bankRange = computed(() =>
        formatRange(props.coverage.bank_from, props.coverage.bank_to),
    );

    let ordersRange = computed(() =>
        formatRange(props.coverage.orders_from, props.coverage.orders_to),
    );

    let hasIncomeContent = computed(() => {
        let income = props.sections.income;

        return income.categories.length > 0 || income.uncategorized !== null;
    });

    let hasSpendingContent = computed(() => {
        let spending = props.sections.spending;

        return (
            spending.bills.categories.length > 0 ||
            spending.expenses.categories.length > 0 ||
            spending.uncategorized !== null
        );
    });

    let isEmpty = computed(
        () => !hasIncomeContent.value && !hasSpendingContent.value,
    );
</script>

<template>
    <div class="space-y-8">
        <div>
            <h1 class="text-2xl font-semibold">Home</h1>
            <p class="text-sm text-neutral-600">
                Income and spending by category. Reimbursement-group
                transactions are excluded.
            </p>
        </div>

        <div class="space-y-3 rounded border px-4 py-3">
            <div class="grid gap-3 sm:grid-cols-2">
                <div>
                    <p class="text-sm text-neutral-600">Income</p>
                    <p class="text-xl font-semibold tabular-nums">
                        {{ formatMoney(total_income) }}
                    </p>
                </div>
                <div>
                    <p class="text-sm text-neutral-600">Spending</p>
                    <p class="text-xl font-semibold tabular-nums">
                        {{ formatMoney(total_spend) }}
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

        <div v-if="isEmpty" class="text-sm text-neutral-600">
            No income or spending yet. Import bank transactions and categorize
            them to see totals here.
        </div>

        <template v-else>
            <section v-if="hasIncomeContent" class="space-y-4">
                <div class="flex flex-wrap items-baseline justify-between gap-2">
                    <h2 class="text-lg font-semibold">Income</h2>
                    <p class="text-sm font-medium tabular-nums text-neutral-700">
                        {{ formatMoney(sections.income.amount) }}
                    </p>
                </div>

                <div
                    class="grid gap-3 sm:grid-cols-2 lg:grid-cols-3"
                >
                    <div
                        v-for="category in sections.income.categories"
                        :key="category.id"
                        class="rounded border border-l-4 px-4 py-3"
                        :class="
                            category.color
                                ? ''
                                : 'border-l-neutral-300 border-dashed bg-neutral-50'
                        "
                        :style="cardStyle(category.color)"
                    >
                        <p class="font-medium">{{ category.name }}</p>
                        <p class="mt-2 text-lg font-semibold tabular-nums">
                            {{ formatMoney(category.amount) }}
                        </p>
                        <p class="text-sm text-neutral-600 tabular-nums">
                            {{ formatPercent(category.percent) }} of
                            {{ kindLabel(category.kind).toLowerCase() }}
                        </p>
                    </div>

                    <div
                        v-if="sections.income.uncategorized"
                        class="rounded border border-dashed border-l-4 border-l-neutral-400 bg-neutral-50 px-4 py-3"
                    >
                        <p class="font-medium">Uncategorized</p>
                        <p class="mt-2 text-lg font-semibold tabular-nums">
                            {{
                                formatMoney(sections.income.uncategorized.amount)
                            }}
                        </p>
                        <p class="text-sm text-neutral-600 tabular-nums">
                            {{
                                formatPercent(
                                    sections.income.uncategorized.percent,
                                )
                            }}
                            of income
                        </p>
                    </div>
                </div>
            </section>

            <section v-if="hasSpendingContent" class="space-y-6">
                <div class="flex flex-wrap items-baseline justify-between gap-2">
                    <h2 class="text-lg font-semibold">Spending</h2>
                    <p class="text-sm font-medium tabular-nums text-neutral-700">
                        {{ formatMoney(sections.spending.amount) }}
                    </p>
                </div>

                <div
                    v-if="sections.spending.bills.categories.length > 0"
                    class="space-y-3"
                >
                    <div
                        class="flex flex-wrap items-baseline justify-between gap-2"
                    >
                        <h3 class="text-sm font-semibold uppercase tracking-wide text-neutral-600">
                            Bills
                        </h3>
                        <p class="text-sm tabular-nums text-neutral-600">
                            {{ formatMoney(sections.spending.bills.amount) }}
                        </p>
                    </div>

                    <div class="grid gap-3 sm:grid-cols-2 lg:grid-cols-3">
                        <div
                            v-for="category in sections.spending.bills
                                .categories"
                            :key="category.id"
                            class="rounded border border-l-4 px-4 py-3"
                            :class="
                                category.color
                                    ? ''
                                    : 'border-l-neutral-300 border-dashed bg-neutral-50'
                            "
                            :style="cardStyle(category.color)"
                        >
                            <p class="font-medium">{{ category.name }}</p>
                            <p class="mt-2 text-lg font-semibold tabular-nums">
                                {{ formatMoney(category.amount) }}
                            </p>
                            <p class="text-sm text-neutral-600 tabular-nums">
                                {{ formatPercent(category.percent) }} of
                                {{ kindLabel(category.kind).toLowerCase() }}s
                            </p>
                        </div>
                    </div>
                </div>

                <div
                    v-if="sections.spending.expenses.categories.length > 0"
                    class="space-y-3"
                >
                    <div
                        class="flex flex-wrap items-baseline justify-between gap-2"
                    >
                        <h3 class="text-sm font-semibold uppercase tracking-wide text-neutral-600">
                            Expenses
                        </h3>
                        <p class="text-sm tabular-nums text-neutral-600">
                            {{ formatMoney(sections.spending.expenses.amount) }}
                        </p>
                    </div>

                    <div class="grid gap-3 sm:grid-cols-2 lg:grid-cols-3">
                        <div
                            v-for="category in sections.spending.expenses
                                .categories"
                            :key="category.id"
                            class="rounded border border-l-4 px-4 py-3"
                            :class="
                                category.color
                                    ? ''
                                    : 'border-l-neutral-300 border-dashed bg-neutral-50'
                            "
                            :style="cardStyle(category.color)"
                        >
                            <p class="font-medium">{{ category.name }}</p>
                            <p class="mt-2 text-lg font-semibold tabular-nums">
                                {{ formatMoney(category.amount) }}
                            </p>
                            <p class="text-sm text-neutral-600 tabular-nums">
                                {{ formatPercent(category.percent) }} of
                                {{ kindLabel(category.kind).toLowerCase() }}s
                            </p>
                        </div>
                    </div>
                </div>

                <div
                    v-if="sections.spending.uncategorized"
                    class="grid gap-3 sm:grid-cols-2 lg:grid-cols-3"
                >
                    <div
                        class="rounded border border-dashed border-l-4 border-l-neutral-400 bg-neutral-50 px-4 py-3"
                    >
                        <p class="font-medium">Uncategorized</p>
                        <p class="mt-2 text-lg font-semibold tabular-nums">
                            {{
                                formatMoney(
                                    sections.spending.uncategorized.amount,
                                )
                            }}
                        </p>
                        <p class="text-sm text-neutral-600 tabular-nums">
                            {{
                                formatPercent(
                                    sections.spending.uncategorized.percent,
                                )
                            }}
                            of spending
                        </p>
                    </div>
                </div>
            </section>
        </template>
    </div>
</template>
