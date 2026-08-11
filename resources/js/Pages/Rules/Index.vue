<script setup>
    import AuthenticatedLayout from '../../Layouts/AuthenticatedLayout.vue';
    import { Link, router, usePage } from '@inertiajs/vue3';
    import { computed } from 'vue';

    defineOptions({ layout: AuthenticatedLayout });

    let props = defineProps({
        tab: {
            type: String,
            default: 'income',
        },
        incomeRules: {
            type: Array,
            required: true,
        },
        expenseRules: {
            type: Array,
            required: true,
        },
        incomeMatchModes: {
            type: Array,
            default: () => [],
        },
        expenseMatchModes: {
            type: Array,
            default: () => [],
        },
    });

    let page = usePage();
    let flashSuccess = computed(() => page.props.flash?.success);

    let tabs = [
        { id: 'income', label: 'Income' },
        { id: 'expenses', label: 'Expenses & bills' },
    ];

    let activeTab = computed(() =>
        props.tab === 'expenses' ? 'expenses' : 'income',
    );

    let descriptionOnlyConfirmedCount = computed(
        () =>
            props.incomeRules.filter(
                (rule) => rule.match_mode === 'description',
            ).length,
    );

    let matchModeLabel = (mode) => {
        return (
            {
                exact_description_and_amount: 'Exact description + amount',
                amount_and_merchant: 'Amount + merchant',
                merchant: 'Merchant only',
                description: 'Description only',
                check_and_amount: 'Check + amount',
                description_prefix_and_amount: 'Starts with + amount',
                once: 'This transaction only',
            }[mode] ?? (mode ? mode : 'Description only')
        );
    };

    let formatMoney = (amount) => {
        return new Intl.NumberFormat('en-US', {
            style: 'currency',
            currency: 'USD',
        }).format(amount);
    };

    let toggleIncomeActive = (rule) => {
        router.patch(`/rules/income/${rule.id}`, {
            is_active: !rule.is_active,
        });
    };

    let deleteIncomeRule = (rule) => {
        if (!window.confirm('Delete this income rule?')) {
            return;
        }

        router.delete(`/rules/income/${rule.id}`);
    };

    let deleteDescriptionOnlyIncomeRules = () => {
        let count = descriptionOnlyConfirmedCount.value;
        if (
            !window.confirm(
                `Delete ${count} description-only income rule${
                    count === 1 ? '' : 's'
                }? Similar credits will no longer be auto-categorized from those rules.`,
            )
        ) {
            return;
        }

        router.delete('/rules/income/description-only');
    };

    let toggleExpenseActive = (rule) => {
        router.patch(`/categorization-rules/${rule.id}`, {
            is_active: !rule.is_active,
        });
    };

    let deleteExpenseRule = (rule) => {
        if (!window.confirm('Delete this categorization rule?')) {
            return;
        }

        router.delete(`/categorization-rules/${rule.id}`);
    };
</script>

<template>
    <div class="space-y-6">
        <div>
            <h1 class="text-2xl font-semibold">Rules</h1>
            <p class="text-sm text-neutral-600">
                Learned match rules that auto-apply to similar transactions.
            </p>
        </div>

        <p
            v-if="flashSuccess"
            class="rounded border border-green-200 bg-green-50 px-3 py-2 text-sm text-green-800"
        >
            {{ flashSuccess }}
        </p>

        <div class="flex flex-wrap gap-2">
            <Link
                v-for="item in tabs"
                :key="item.id"
                :href="`/rules?tab=${item.id}`"
                class="rounded border px-3 py-1.5 text-sm"
                :class="
                    activeTab === item.id
                        ? 'border-neutral-900 bg-neutral-900 text-white'
                        : 'text-neutral-700'
                "
            >
                {{ item.label }}
            </Link>
        </div>

        <section v-if="activeTab === 'income'" class="space-y-4">
            <div class="flex flex-wrap items-start justify-between gap-3">
                <p class="text-sm text-neutral-600">
                    Income rules created when you categorize credits as income
                    on Reconciliation.
                </p>
                <button
                    v-if="descriptionOnlyConfirmedCount > 0"
                    type="button"
                    class="rounded border px-3 py-1.5 text-sm text-red-700"
                    @click="deleteDescriptionOnlyIncomeRules"
                >
                    Delete description-only ({{
                        descriptionOnlyConfirmedCount
                    }})
                </button>
            </div>

            <div
                v-if="incomeRules.length === 0"
                class="text-sm text-neutral-600"
            >
                No income rules yet. Categorize a credit as income on
                Reconciliation to create one.
            </div>

            <ul v-else class="divide-y rounded border">
                <li
                    v-for="rule in incomeRules"
                    :key="rule.id"
                    class="space-y-2 px-4 py-3"
                >
                    <div
                        class="flex flex-wrap items-start justify-between gap-3"
                    >
                        <div>
                            <p class="font-medium">
                                {{ rule.normalized_pattern || 'No pattern' }}
                            </p>
                            <p class="mt-0.5 text-sm font-medium text-neutral-800">
                                Rule type:
                                {{ matchModeLabel(rule.match_mode) }}
                            </p>
                            <p class="text-sm text-neutral-600">
                                Income ·
                                {{ rule.category?.name ?? 'No category' }}
                            </p>
                            <p
                                v-if="rule.amount !== null"
                                class="text-sm text-neutral-600"
                            >
                                Amount: {{ formatMoney(rule.amount) }}
                            </p>
                        </div>
                        <div class="flex flex-wrap gap-2 text-sm">
                            <button
                                type="button"
                                class="rounded border px-3 py-1.5"
                                @click="toggleIncomeActive(rule)"
                            >
                                {{ rule.is_active ? 'Disable' : 'Enable' }}
                            </button>
                            <button
                                type="button"
                                class="rounded border px-3 py-1.5 text-red-700"
                                @click="deleteIncomeRule(rule)"
                            >
                                Delete
                            </button>
                        </div>
                    </div>
                    <p
                        class="text-xs"
                        :class="
                            rule.is_active
                                ? 'text-green-700'
                                : 'text-neutral-500'
                        "
                    >
                        {{ rule.is_active ? 'Active' : 'Disabled' }}
                    </p>
                </li>
            </ul>
        </section>

        <section v-else class="space-y-4">
            <p class="text-sm text-neutral-600">
                Expense and bill rules created when you categorize debit
                transactions on Reconciliation.
            </p>

            <div
                v-if="expenseRules.length === 0"
                class="text-sm text-neutral-600"
            >
                No expense/bill rules yet. Categorize a transaction on
                Reconciliation to create one.
            </div>

            <ul v-else class="divide-y rounded border">
                <li
                    v-for="rule in expenseRules"
                    :key="rule.id"
                    class="space-y-2 px-4 py-3"
                >
                    <div
                        class="flex flex-wrap items-start justify-between gap-3"
                    >
                        <div>
                            <p class="font-medium">
                                {{ rule.category?.name ?? 'Unknown category' }}
                                <span
                                    class="text-sm font-normal text-neutral-600"
                                >
                                    · {{ rule.classification }}
                                </span>
                            </p>
                            <p class="text-sm text-neutral-600">
                                {{ matchModeLabel(rule.match_mode) }}
                            </p>
                            <p
                                v-if="rule.merchant"
                                class="text-sm text-neutral-600"
                            >
                                Merchant: {{ rule.merchant.name }}
                            </p>
                            <p
                                v-if="rule.normalized_pattern"
                                class="text-sm text-neutral-600"
                            >
                                Pattern: {{ rule.normalized_pattern }}
                            </p>
                            <p
                                v-if="rule.amount !== null"
                                class="text-sm text-neutral-600"
                            >
                                Amount: {{ formatMoney(rule.amount) }}
                            </p>
                        </div>
                        <div class="flex flex-wrap gap-2 text-sm">
                            <button
                                type="button"
                                class="rounded border px-3 py-1.5"
                                @click="toggleExpenseActive(rule)"
                            >
                                {{ rule.is_active ? 'Disable' : 'Enable' }}
                            </button>
                            <button
                                type="button"
                                class="rounded border px-3 py-1.5 text-red-700"
                                @click="deleteExpenseRule(rule)"
                            >
                                Delete
                            </button>
                        </div>
                    </div>
                    <p
                        class="text-xs"
                        :class="
                            rule.is_active
                                ? 'text-green-700'
                                : 'text-neutral-500'
                        "
                    >
                        {{ rule.is_active ? 'Active' : 'Disabled' }}
                    </p>
                </li>
            </ul>
        </section>
    </div>
</template>
