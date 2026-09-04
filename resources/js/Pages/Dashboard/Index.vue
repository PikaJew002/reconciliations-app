<script setup>
    import AuthenticatedLayout from '../../Layouts/AuthenticatedLayout.vue';
    import PeriodReport from './PeriodReport.vue';
    import { Link, router } from '@inertiajs/vue3';
    import { computed } from 'vue';

    defineOptions({ layout: AuthenticatedLayout });

    let props = defineProps({
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
        month_report: {
            type: Object,
            required: true,
        },
        year_report: {
            type: Object,
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

    let paycheckRemaining = computed(() => {
        if (!props.paycheck_leftover) {
            return null;
        }

        return Number(props.paycheck_leftover.remaining);
    });

    let runningLeftover = computed(() => {
        if (!props.paycheck_leftover) {
            return null;
        }

        return Number(props.paycheck_leftover.remaining);
    });

    let leftoverUntilLabel = computed(() => {
        let nextDate = props.paycheck_leftover?.next_paycheck?.date;

        if (!nextDate) {
            return 'Left to spend this paycheck';
        }

        return `Left to spend until ${formatDay(nextDate)}`;
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

    let shiftMonth = (delta) => {
        let [year, month] = props.month.split('-').map(Number);
        let date = new Date(year, month - 1 + delta, 1);
        let next = `${date.getFullYear()}-${String(date.getMonth() + 1).padStart(2, '0')}`;

        router.get(
            '/',
            { ...queryBase(), month: next },
            { preserveState: true, replace: true },
        );
    };

    let selectBudgetYear = (budgetYearId) => {
        router.get(
            '/',
            {
                ...queryBase(),
                budget_year_id: budgetYearId,
                month: props.month,
            },
            { preserveState: true, replace: true },
        );
    };

    let hasPaycheckPlans = computed(
        () => (props.paycheck_plans?.paychecks?.length ?? 0) > 0,
    );
</script>

<template>
    <div class="space-y-10">
        <div>
            <h1 class="text-2xl font-semibold">Home</h1>
            <p class="text-sm text-neutral-600">
                This paycheck, this month, and this budget year.
                Reimbursement-group transactions are excluded.
            </p>
        </div>

        <section class="space-y-4">
            <div>
                <h2 class="text-lg font-semibold">This paycheck</h2>
                <p class="text-sm text-neutral-600">
                    What is left to spend until the next check, and which bills
                    hit which upcoming paycheck.
                </p>
            </div>

            <div
                v-if="paycheck_leftover"
                class="space-y-2 rounded border px-4 py-3"
            >
                <p class="text-sm text-neutral-600">{{ leftoverUntilLabel }}</p>
                <p
                    v-if="paycheckRemaining >= 0"
                    class="text-2xl font-semibold tabular-nums"
                    :class="differenceClass(paycheckRemaining)"
                >
                    {{ formatMoney(paycheckRemaining) }} remaining
                </p>
                <p
                    v-else
                    class="text-2xl font-semibold tabular-nums text-red-700"
                >
                    {{ formatMoney(-paycheckRemaining) }} into the next paycheck
                </p>
                <p class="text-sm text-neutral-600">
                    Brought forward
                    {{ formatMoney(paycheck_leftover.brought_forward) }}
                    + {{ paycheck_leftover.paycheck.name }} after bills
                    {{ formatMoney(paycheck_leftover.planned_leftover) }}
                    − spent {{ formatMoney(paycheck_leftover.spent) }}
                    <template
                        v-if="(paycheck_leftover.credit_card_payments ?? 0) > 0"
                    >
                        − card payments
                        {{ formatMoney(paycheck_leftover.credit_card_payments) }}
                    </template>
                    <template
                        v-if="(paycheck_leftover.savings_transfers ?? 0) > 0"
                    >
                        − to savings
                        {{ formatMoney(paycheck_leftover.savings_transfers) }}
                    </template>
                    <template
                        v-else-if="(paycheck_leftover.savings_transfers ?? 0) < 0"
                    >
                        + from savings
                        {{ formatMoney(-paycheck_leftover.savings_transfers) }}
                    </template>
                </p>
                <p
                    v-if="paycheck_leftover.next_paycheck"
                    class="text-sm text-neutral-600"
                >
                    Next paycheck
                    {{ formatDay(paycheck_leftover.next_paycheck.date) }}
                    <span v-if="paycheck_leftover.days_remaining !== null">
                        ({{ paycheck_leftover.days_remaining }}
                        {{
                            paycheck_leftover.days_remaining === 1
                                ? 'day'
                                : 'days'
                        }})
                    </span>
                </p>
                <p
                    v-if="unassignedBillsAmount > 0"
                    class="text-sm text-amber-800"
                >
                    {{ formatMoney(unassignedBillsAmount) }} of unplanned bills
                    in this window
                </p>
            </div>

            <p v-else class="text-sm text-neutral-600">
                Plan paychecks on
                <Link href="/plans" class="underline">Plans</Link>
                to track what is left to spend until the next check.
            </p>

            <div v-if="hasPaycheckPlans" class="space-y-4">
                <div
                    class="flex flex-wrap items-baseline justify-between gap-2"
                >
                    <h3 class="text-base font-semibold">Upcoming paychecks</h3>
                    <p
                        class="text-sm font-medium tabular-nums"
                        :class="differenceClass(paycheck_plans.leftover)"
                    >
                        Planned after bills
                        {{ formatMoney(paycheck_plans.leftover) }}
                    </p>
                </div>

                <div
                    class="grid grid-cols-1 gap-1 sm:grid-cols-2 sm:gap-2 lg:grid-cols-4"
                >
                    <div
                        v-for="paycheck in paycheck_plans.paychecks"
                        :key="paycheck.id"
                        class="rounded border px-4 py-3"
                        :class="paycheck.is_current ? 'border-brand' : ''"
                    >
                        <div class="flex items-start justify-between gap-3">
                            <div>
                                <p class="font-medium">
                                    {{ paycheck.name }}
                                    <span
                                        v-if="paycheck.is_current"
                                        class="ml-1 text-xs font-normal text-neutral-500"
                                    >
                                        current
                                    </span>
                                </p>
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
                            <span>After bills</span>
                            <span
                                class="tabular-nums"
                                :class="differenceClass(paycheck.leftover)"
                            >
                                {{ formatMoney(paycheck.leftover) }}
                            </span>
                        </p>
                    </div>
                </div>
            </div>
        </section>

        <section class="space-y-4">
            <div class="flex flex-wrap items-start justify-between gap-3">
                <div>
                    <h2 class="text-lg font-semibold">This month</h2>
                    <p class="text-sm text-neutral-600">
                        {{ month_report.period.label }}
                        · {{ month_report.period.from }} →
                        {{ month_report.period.to }}
                    </p>
                    <p class="text-sm text-neutral-600">
                        Income vs expenses this month.
                    </p>
                </div>

                <div class="flex gap-2">
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

            <PeriodReport
                :summary="month_report.summary"
                :sections="month_report.sections"
                :total-spend="month_report.total_spend"
            />

            <p class="text-sm text-neutral-600">
                Set monthly budgets on
                <Link href="/budgets" class="underline">Budgets</Link>. Plan
                paychecks on
                <Link href="/plans" class="underline">Plans</Link>.
            </p>
        </section>

        <section class="space-y-4">
            <div>
                <h2 class="text-lg font-semibold">This budget year</h2>
                <p class="text-sm text-neutral-600">
                    {{ year_report.period.label }}
                    · {{ year_report.period.from }} →
                    {{ year_report.period.to }}
                    · {{ year_report.months_elapsed }}
                    {{
                        year_report.months_elapsed === 1 ? 'month' : 'months'
                    }}
                    so far
                </p>
                <p class="text-sm text-neutral-600">
                    Income vs expenses for the budget year to date.
                </p>
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

            <p
                v-if="budget_year"
                class="inline-flex rounded border px-2 py-0.5 text-xs font-medium"
                :style="chipStyle(budget_year.color)"
            >
                {{ budget_year.label }}
            </p>

            <p
                v-if="runningLeftover !== null"
                class="text-sm"
                :class="differenceClass(runningLeftover)"
            >
                Running leftover
                <template v-if="runningLeftover > 0">
                    {{ formatMoney(runningLeftover) }} ahead
                </template>
                <template v-else-if="runningLeftover < 0">
                    {{ formatMoney(-runningLeftover) }} behind
                </template>
                <template v-else>even</template>
                <template v-if="leftover_origin">
                    since
                    {{
                        formatDay(
                            leftover_origin.paycheck?.date ||
                                leftover_origin.starts_on,
                        )
                    }}.
                    <Link href="/plans" class="underline">Change on Plans</Link>
                </template>
            </p>

            <PeriodReport
                :summary="year_report.summary"
                :sections="year_report.sections"
                :total-spend="year_report.total_spend"
                categories-collapsed
            />
        </section>
    </div>
</template>
