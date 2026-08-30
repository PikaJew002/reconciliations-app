<script setup>
    import AuthenticatedLayout from '../../Layouts/AuthenticatedLayout.vue';
    import { Link, router } from '@inertiajs/vue3';
    import { computed } from 'vue';

    defineOptions({ layout: AuthenticatedLayout });

    let props = defineProps({
        view: {
            type: String,
            required: true,
        },
        month: {
            type: String,
            required: true,
        },
        budget_year: {
            type: Object,
            default: null,
        },
        budget_years: {
            type: Array,
            default: () => [],
        },
        period: {
            type: Object,
            required: true,
        },
        total_income: {
            type: Number,
            required: true,
        },
        total_spend: {
            type: Number,
            required: true,
        },
        summary: {
            type: Object,
            required: true,
        },
        sections: {
            type: Object,
            required: true,
        },
        months_elapsed: {
            type: Number,
            required: true,
        },
        paycheck_plans: {
            type: Object,
            default: () => ({
                paychecks: [],
                income: 0,
                bills: 0,
                leftover: 0,
            }),
        },
        paycheck_leftover: {
            type: Object,
            default: null,
        },
        leftover_origin: {
            type: Object,
            default: null,
        },
    });

    let queryBase = () => {
        let query = {};

        if (props.budget_year?.id) {
            query.budget_year_id = props.budget_year.id;
        }

        return query;
    };

    let chipStyle = (color) => {
        if (!color) {
            return undefined;
        }

        return {
            borderColor: color,
            backgroundColor: `${color}22`,
        };
    };

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

    let formatDay = (date) => {
        if (!date) {
            return '—';
        }

        return new Date(`${date}T00:00:00`).toLocaleDateString('en-US', {
            month: 'short',
            day: 'numeric',
        });
    };

    let unassignedBillsAmount = computed(() => {
        return roundMoney(
            (props.paycheck_leftover?.unassigned_bills ?? []).reduce(
                (total, bill) => total + Number(bill.amount ?? 0),
                0,
            ),
        );
    });

    let roundMoney = (amount) => {
        return Math.round(Number(amount) * 100) / 100;
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

    let setView = (view) => {
        router.get(
            '/',
            view === 'month'
                ? { ...queryBase(), view: 'month', month: props.month }
                : { ...queryBase(), view: 'ytm' },
            { preserveState: true, replace: true },
        );
    };

    let shiftMonth = (delta) => {
        let [year, month] = props.month.split('-').map(Number);
        let date = new Date(year, month - 1 + delta, 1);
        let next = `${date.getFullYear()}-${String(date.getMonth() + 1).padStart(2, '0')}`;

        router.get(
            '/',
            { ...queryBase(), view: 'month', month: next },
            { preserveState: true, replace: true },
        );
    };

    let selectBudgetYear = (budgetYearId) => {
        router.get(
            '/',
            props.view === 'month'
                ? {
                      ...queryBase(),
                      budget_year_id: budgetYearId,
                      view: 'month',
                      month: props.month,
                  }
                : { budget_year_id: budgetYearId, view: 'ytm' },
            { preserveState: true, replace: true },
        );
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

    let hasPaycheckPlans = computed(
        () =>
            props.view === 'month' &&
            (props.paycheck_plans?.paychecks?.length ?? 0) > 0,
    );
</script>

<template>
    <div class="space-y-8">
        <div class="flex flex-wrap items-start justify-between gap-4">
            <div>
                <h1 class="text-2xl font-semibold">Home</h1>
                <p class="text-sm text-neutral-600">
                    Income and spending by category for the selected period.
                    Reimbursement-group transactions are excluded.
                </p>
            </div>

            <div class="flex flex-wrap gap-2">
                <button
                    type="button"
                    class="rounded border px-3 py-1.5 text-sm"
                    :class="
                        view === 'month'
                            ? 'border-brand bg-brand text-white'
                            : 'hover:bg-neutral-50'
                    "
                    @click="setView('month')"
                >
                    Month
                </button>
                <button
                    type="button"
                    class="rounded border px-3 py-1.5 text-sm"
                    :class="
                        view === 'ytm'
                            ? 'border-brand bg-brand text-white'
                            : 'hover:bg-neutral-50'
                    "
                    @click="setView('ytm')"
                >
                    Year to month
                </button>
            </div>
        </div>

        <div
            v-if="budget_years.length > 0"
            class="flex flex-wrap items-center gap-2"
        >
            <button
                v-for="year in budget_years"
                :key="year.id"
                type="button"
                class="rounded border px-3 py-1.5 text-sm"
                :class="
                    budget_year?.id === year.id
                        ? 'font-semibold'
                        : 'hover:bg-neutral-50'
                "
                :style="chipStyle(year.color)"
                @click="selectBudgetYear(year.id)"
            >
                {{ year.label }}
                <span
                    v-if="year.is_current"
                    class="ml-1 text-xs text-neutral-600"
                >
                    (current)
                </span>
            </button>
        </div>

        <div class="flex flex-wrap items-center justify-between gap-3">
            <div>
                <p class="text-lg font-medium">{{ period.label }}</p>
                <p class="text-sm text-neutral-600">
                    {{ period.from }} → {{ period.to }}
                    <span v-if="view === 'ytm'">
                        · {{ months_elapsed }}
                        {{ months_elapsed === 1 ? 'month' : 'months' }}
                    </span>
                </p>
                <p
                    v-if="budget_year"
                    class="mt-1 inline-flex rounded border px-2 py-0.5 text-xs font-medium"
                    :style="chipStyle(budget_year.color)"
                >
                    {{ budget_year.label }}
                </p>
            </div>

            <div v-if="view === 'month'" class="flex gap-2">
                <button
                    type="button"
                    class="btn rounded border px-3 text-sm hover:bg-neutral-50"
                    @click="shiftMonth(-1)"
                >
                    Previous
                </button>
                <button
                    type="button"
                    class="btn rounded border px-3 text-sm hover:bg-neutral-50"
                    @click="shiftMonth(1)"
                >
                    Next
                </button>
            </div>
        </div>

        <section
            v-if="paycheck_leftover"
            class="space-y-2 rounded border px-4 py-3"
        >
            <p class="text-sm text-neutral-600">Leftover until next paycheck</p>
            <p
                v-if="paycheck_leftover.remaining >= 0"
                class="text-2xl font-semibold tabular-nums"
                :class="differenceClass(paycheck_leftover.remaining)"
            >
                {{ formatMoney(paycheck_leftover.remaining) }} remaining
            </p>
            <p
                v-else
                class="text-2xl font-semibold tabular-nums text-red-700"
            >
                {{ formatMoney(-paycheck_leftover.remaining) }} into the next
                paycheck
            </p>
            <p class="text-sm text-neutral-600">
                Brought forward
                {{ formatMoney(paycheck_leftover.brought_forward) }}
                + {{ paycheck_leftover.paycheck.name }} leftover
                {{ formatMoney(paycheck_leftover.planned_leftover) }}
                − spent {{ formatMoney(paycheck_leftover.spent) }}
                <template v-if="(paycheck_leftover.allocated ?? 0) > 0">
                    − transferred
                    {{ formatMoney(paycheck_leftover.allocated) }}
                </template>
                <template v-else-if="(paycheck_leftover.allocated ?? 0) < 0">
                    + from savings
                    {{ formatMoney(-paycheck_leftover.allocated) }}
                </template>
            </p>
            <p
                v-if="paycheck_leftover.next_paycheck"
                class="text-sm text-neutral-600"
            >
                Until
                {{ formatDay(paycheck_leftover.next_paycheck.date) }}
                paycheck
                <span v-if="paycheck_leftover.days_remaining !== null">
                    ({{ paycheck_leftover.days_remaining }}
                    {{
                        paycheck_leftover.days_remaining === 1 ? 'day' : 'days'
                    }})
                </span>
            </p>
            <p
                v-if="unassignedBillsAmount > 0"
                class="text-sm text-amber-800"
            >
                {{ formatMoney(unassignedBillsAmount) }} of unplanned bills in
                this window
            </p>
            <p
                v-if="leftover_origin"
                class="text-sm text-neutral-600"
            >
                Tracking since
                {{
                    formatDay(
                        leftover_origin.paycheck?.date ||
                            leftover_origin.starts_on,
                    )
                }}.
                <Link href="/plans" class="underline">Change on Plans</Link>
            </p>
        </section>

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
                    <p class="text-sm text-neutral-600">Leftover income</p>
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
                        {{ formatMoney(total_spend) }}
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
                    <p class="text-sm font-medium">Expenses vs leftover</p>
                    <p class="mt-1 text-sm text-neutral-600">
                        Spent {{ formatMoney(summary.expenses) }} of
                        {{ formatMoney(summary.leftover_income) }} leftover
                        after bills
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

            <p class="text-sm text-neutral-600">
                Set monthly budgets on
                <Link href="/budgets" class="underline">Budgets</Link>. Plan
                paychecks on
                <Link href="/plans" class="underline">Plans</Link>.
            </p>
        </div>

        <section v-if="hasPaycheckPlans" class="space-y-4">
            <div class="flex flex-wrap items-baseline justify-between gap-2">
                <h2 class="text-lg font-semibold">
                    Upcoming Paychecks / Bills
                </h2>
                <p
                    class="text-sm font-medium tabular-nums"
                    :class="differenceClass(paycheck_plans.leftover)"
                >
                    Leftover {{ formatMoney(paycheck_plans.leftover) }}
                </p>
            </div>

            <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-1 sm:gap-2">
                <div
                    v-for="paycheck in paycheck_plans.paychecks"
                    :key="paycheck.id"
                    class="rounded border px-4 py-3"
                >
                    <div class="flex items-start justify-between gap-3">
                        <div>
                            <p class="font-medium">{{ paycheck.name }}</p>
                            <p class="text-sm text-neutral-600">
                                {{ paycheck.expected_date }}
                            </p>
                        </div>
                        <p class="font-semibold tabular-nums">
                            {{ formatMoney(paycheck.amount) }}
                        </p>
                    </div>

                    <ul class="mt-3 space-y-1 text-sm">
                        <li
                            v-if="paycheck.bills.length === 0"
                            class="text-neutral-500"
                        >
                            No bills assigned
                        </li>
                        <li
                            v-for="bill in paycheck.bills"
                            :key="bill.id"
                            class="flex items-baseline justify-between gap-3 text-neutral-700"
                        >
                            <span>
                                {{ bill.name }}
                                <span class="text-neutral-500">
                                    {{ bill.expected_date }}
                                </span>
                            </span>
                            <span class="tabular-nums">
                                {{ formatMoney(bill.amount) }}
                            </span>
                        </li>
                    </ul>

                    <p
                        class="mt-3 flex items-baseline justify-between gap-3 border-t pt-2 text-sm font-medium"
                    >
                        <span>Leftover</span>
                        <span
                            class="tabular-nums"
                            :class="differenceClass(paycheck.leftover)"
                        >
                            {{ formatMoney(paycheck.leftover) }}
                        </span>
                    </p>
                </div>
            </div>
        </section>

        <div v-if="isEmpty" class="text-sm text-neutral-600">
            No income or spending in this period. Import bank transactions and
            categorize them to see totals here.
        </div>

        <template v-else>
            <section v-if="hasIncomeContent" class="space-y-4">
                <div class="flex flex-wrap items-baseline justify-between gap-2">
                    <h2 class="text-lg font-semibold">Income</h2>
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
                    <h2 class="text-lg font-semibold">Spending</h2>
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
                        <h3
                            class="text-sm font-semibold uppercase tracking-wide text-neutral-600"
                        >
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
                        <h3
                            class="text-sm font-semibold uppercase tracking-wide text-neutral-600"
                        >
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
        </template>
    </div>
</template>
