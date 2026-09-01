<script setup>
    import { computed } from 'vue';

    let props = defineProps({
        summary: {
            type: Object,
            required: true,
        },
        sections: {
            type: Object,
            required: true,
        },
        totalSpend: {
            type: Number,
            required: true,
        },
        categoriesCollapsed: {
            type: Boolean,
            default: false,
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
        if (amount === null || amount === undefined) {
            return '—';
        }

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

    let differenceClass = (value) => {
        if (value === null || value === undefined) {
            return 'text-neutral-500';
        }

        if (value < 0) {
            return 'font-medium text-red-700';
        }

        if (value > 0) {
            return 'text-emerald-700';
        }

        return 'text-neutral-700';
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

    let hasIncomeContent = computed(() => {
        let income = props.sections.income;

        return income.categories.length > 0 || income.uncategorized !== null;
    });

    let hasSpendingContent = computed(() => {
        let spending = props.sections.spending;

        return (
            spending.bills.categories.length > 0 ||
            spending.bills.uncategorized !== null ||
            spending.expenses.categories.length > 0 ||
            spending.expenses.uncategorized !== null
        );
    });

    let isEmpty = computed(
        () => !hasIncomeContent.value && !hasSpendingContent.value,
    );
</script>

<template>
    <div class="space-y-6">
        <div class="space-y-3 rounded border px-4 py-3">
            <div class="grid gap-3 sm:grid-cols-2 lg:grid-cols-4">
                <div>
                    <p class="text-sm text-neutral-600">Income</p>
                    <p class="text-xl font-semibold tabular-nums">
                        {{ formatMoney(summary.income) }}
                    </p>
                    <p class="mt-1 text-xs text-neutral-600">
                        Received {{ formatMoney(summary.income_received) }}
                        · {{ formatMoney(summary.income_planned) }} still
                        planned
                    </p>
                </div>
                <div>
                    <p class="text-sm text-neutral-600">Bills</p>
                    <p class="text-xl font-semibold tabular-nums">
                        {{ formatMoney(summary.bills) }}
                    </p>
                </div>
                <div>
                    <p class="text-sm text-neutral-600">Income after bills</p>
                    <p
                        class="text-xl font-semibold tabular-nums"
                        :class="differenceClass(summary.leftover_income)"
                    >
                        {{ formatMoney(summary.leftover_income) }}
                    </p>
                </div>
                <div>
                    <p class="text-sm text-neutral-600">Spending</p>
                    <p class="text-xl font-semibold tabular-nums">
                        {{ formatMoney(totalSpend) }}
                    </p>
                </div>
            </div>

            <div class="grid gap-3 sm:grid-cols-2 lg:grid-cols-4">
                <div class="rounded border px-3 py-2">
                    <p class="text-sm font-medium">Income vs budget</p>
                    <p class="mt-1 text-sm text-neutral-600">
                        Received {{ formatMoney(summary.income) }} of
                        {{ formatMoney(summary.income_budget_allowed) }}
                    </p>
                    <p
                        class="mt-1 font-semibold tabular-nums"
                        :class="
                            differenceClass(summary.income_vs_budget_difference)
                        "
                    >
                        {{ formatMoney(summary.income_vs_budget_difference) }}
                        vs target
                    </p>
                </div>
                <div class="rounded border px-3 py-2">
                    <p class="text-sm font-medium">Bills vs budget</p>
                    <p class="mt-1 text-sm text-neutral-600">
                        Spent {{ formatMoney(summary.bills) }} of
                        {{ formatMoney(summary.bills_budget_allowed) }}
                    </p>
                    <p
                        class="mt-1 font-semibold tabular-nums"
                        :class="
                            differenceClass(summary.bills_vs_budget_difference)
                        "
                    >
                        {{ formatMoney(summary.bills_vs_budget_difference) }}
                        remaining
                    </p>
                </div>
                <div class="rounded border px-3 py-2">
                    <p class="text-sm font-medium">Expenses vs budget</p>
                    <p class="mt-1 text-sm text-neutral-600">
                        Spent {{ formatMoney(summary.expenses) }} of
                        {{ formatMoney(summary.budget_allowed) }}
                    </p>
                    <p
                        class="mt-1 font-semibold tabular-nums"
                        :class="differenceClass(summary.vs_budget_difference)"
                    >
                        {{ formatMoney(summary.vs_budget_difference) }}
                        remaining
                    </p>
                </div>
                <div class="rounded border px-3 py-2">
                    <p class="text-sm font-medium">
                        Expenses vs income after bills
                    </p>
                    <p class="mt-1 text-sm text-neutral-600">
                        Spent {{ formatMoney(summary.expenses) }} of
                        {{ formatMoney(summary.leftover_income) }} after bills
                    </p>
                    <p
                        class="mt-1 font-semibold tabular-nums"
                        :class="differenceClass(summary.vs_leftover_difference)"
                    >
                        {{ formatMoney(summary.vs_leftover_difference) }}
                        remaining
                    </p>
                </div>
            </div>
        </div>

        <div v-if="isEmpty" class="text-sm text-neutral-600">
            No income or spending in this period. Import bank transactions and
            categorize them to see totals here.
        </div>

        <component
            :is="categoriesCollapsed ? 'details' : 'div'"
            v-else
            class="space-y-6"
        >
            <summary
                v-if="categoriesCollapsed"
                class="cursor-pointer text-sm font-medium text-neutral-700"
            >
                Category breakdown
            </summary>

            <section v-if="hasIncomeContent" class="space-y-4">
                <div class="flex flex-wrap items-baseline justify-between gap-2">
                    <h3 class="text-lg font-semibold">Income</h3>
                    <p class="text-sm font-medium tabular-nums text-neutral-700">
                        {{ formatMoney(sections.income.amount) }}
                    </p>
                </div>

                <div class="grid gap-3 sm:grid-cols-2 lg:grid-cols-3">
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
                        <div
                            class="mt-2 flex items-start justify-between gap-3"
                        >
                            <div>
                                <p class="text-lg font-semibold tabular-nums">
                                    {{ formatMoney(category.amount) }}
                                </p>
                                <p class="text-sm text-neutral-600 tabular-nums">
                                    {{ formatPercent(category.percent) }} of
                                    {{ kindLabel(category.kind).toLowerCase() }}
                                </p>
                            </div>
                            <div class="text-right text-sm">
                                <p class="text-neutral-600">Budget</p>
                                <p class="font-semibold tabular-nums">
                                    {{ formatMoney(category.budget_allowed) }}
                                </p>
                                <p
                                    class="tabular-nums"
                                    :class="
                                        differenceClass(
                                            category.vs_budget_difference,
                                        )
                                    "
                                >
                                    {{
                                        formatMoney(
                                            category.vs_budget_difference,
                                        )
                                    }}
                                    vs budget
                                </p>
                            </div>
                        </div>
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
                    <h3 class="text-lg font-semibold">Spending</h3>
                    <p class="text-sm font-medium tabular-nums text-neutral-700">
                        {{ formatMoney(sections.spending.amount) }}
                    </p>
                </div>

                <div
                    v-if="
                        sections.spending.bills.categories.length > 0 ||
                        sections.spending.bills.uncategorized
                    "
                    class="space-y-3"
                >
                    <div
                        class="flex flex-wrap items-baseline justify-between gap-2"
                    >
                        <h4
                            class="text-sm font-semibold uppercase tracking-wide text-neutral-600"
                        >
                            Bills
                        </h4>
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
                            <div
                                class="mt-2 flex items-start justify-between gap-3"
                            >
                                <div>
                                    <p
                                        class="text-lg font-semibold tabular-nums"
                                    >
                                        {{ formatMoney(category.amount) }}
                                    </p>
                                    <p
                                        class="text-sm text-neutral-600 tabular-nums"
                                    >
                                        {{ formatPercent(category.percent) }} of
                                        {{
                                            kindLabel(
                                                category.kind,
                                            ).toLowerCase()
                                        }}s
                                    </p>
                                </div>
                                <div class="text-right text-sm">
                                    <p class="text-neutral-600">Budget</p>
                                    <p class="font-semibold tabular-nums">
                                        {{
                                            formatMoney(category.budget_allowed)
                                        }}
                                    </p>
                                    <p
                                        class="tabular-nums"
                                        :class="
                                            differenceClass(
                                                category.vs_budget_difference,
                                            )
                                        "
                                    >
                                        {{
                                            formatMoney(
                                                category.vs_budget_difference,
                                            )
                                        }}
                                        vs budget
                                    </p>
                                </div>
                            </div>
                        </div>

                        <div
                            v-if="sections.spending.bills.uncategorized"
                            class="rounded border border-dashed border-l-4 border-l-neutral-400 bg-neutral-50 px-4 py-3"
                        >
                            <p class="font-medium">Uncategorized</p>
                            <p class="mt-2 text-lg font-semibold tabular-nums">
                                {{
                                    formatMoney(
                                        sections.spending.bills.uncategorized
                                            .amount,
                                    )
                                }}
                            </p>
                            <p class="text-sm text-neutral-600 tabular-nums">
                                {{
                                    formatPercent(
                                        sections.spending.bills.uncategorized
                                            .percent,
                                    )
                                }}
                                of bills
                            </p>
                        </div>
                    </div>
                </div>

                <div
                    v-if="
                        sections.spending.expenses.categories.length > 0 ||
                        sections.spending.expenses.uncategorized
                    "
                    class="space-y-3"
                >
                    <div
                        class="flex flex-wrap items-baseline justify-between gap-2"
                    >
                        <h4
                            class="text-sm font-semibold uppercase tracking-wide text-neutral-600"
                        >
                            Expenses
                        </h4>
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

                            <div
                                class="mt-2 flex items-start justify-between gap-3"
                            >
                                <div>
                                    <p
                                        class="text-lg font-semibold tabular-nums"
                                    >
                                        {{ formatMoney(category.amount) }}
                                    </p>
                                    <p
                                        class="text-sm text-neutral-600 tabular-nums"
                                    >
                                        {{ formatPercent(category.percent) }} of
                                        expenses
                                    </p>
                                </div>

                                <div class="text-right text-sm">
                                    <p class="text-neutral-600">Budget</p>
                                    <p class="font-semibold tabular-nums">
                                        {{
                                            formatMoney(category.budget_allowed)
                                        }}
                                    </p>
                                    <p
                                        class="tabular-nums"
                                        :class="
                                            differenceClass(
                                                category.vs_budget_difference,
                                            )
                                        "
                                    >
                                        {{
                                            formatMoney(
                                                category.vs_budget_difference,
                                            )
                                        }}
                                        vs budget
                                    </p>
                                </div>
                            </div>
                        </div>

                        <div
                            v-if="sections.spending.expenses.uncategorized"
                            class="rounded border border-dashed border-l-4 border-l-neutral-400 bg-neutral-50 px-4 py-3"
                        >
                            <p class="font-medium">Uncategorized</p>
                            <p class="mt-2 text-lg font-semibold tabular-nums">
                                {{
                                    formatMoney(
                                        sections.spending.expenses.uncategorized
                                            .amount,
                                    )
                                }}
                            </p>
                            <p class="text-sm text-neutral-600 tabular-nums">
                                {{
                                    formatPercent(
                                        sections.spending.expenses.uncategorized
                                            .percent,
                                    )
                                }}
                                of expenses
                            </p>
                        </div>
                    </div>
                </div>
            </section>
        </component>
    </div>
</template>
