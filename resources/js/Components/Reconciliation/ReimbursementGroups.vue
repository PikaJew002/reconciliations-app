<script setup>
    import {
        accountLabel,
        formatMoney,
    } from '../../Composables/useReconciliationFormatting.js';
    import { router } from '@inertiajs/vue3';
    import { computed, reactive, ref } from 'vue';

    let props = defineProps({
        openReimbursementGroups: {
            type: Array,
            default: () => [],
        },
        closedReimbursementGroups: {
            type: Array,
            default: () => [],
        },
        reimbursementEligibleTransactions: {
            type: Array,
            default: () => [],
        },
        categories: {
            type: Array,
            default: () => [],
        },
    });

    let reimbursementActionKey = ref(null);
    let closeForms = reactive({});
    let addToGroupSelections = reactive({});

    let billCategories = computed(() =>
        props.categories.filter((category) => category.kind === 'bill'),
    );
    let expenseCategories = computed(() =>
        props.categories.filter((category) => category.kind === 'expense'),
    );

    function reimbursementNetState(net) {
        let value = Number(net);

        if (value <= -0.01) {
            return 'surplus';
        }

        if (value >= 0.01) {
            return 'under';
        }

        return 'balanced';
    }

    function categoriesForClassification(classification) {
        return classification === 'bill'
            ? billCategories.value
            : expenseCategories.value;
    }

    function eligibleForGroup(group) {
        let memberIds = new Set(
            (group.legs || [])
                .map((leg) => leg.transaction?.id)
                .filter(Boolean),
        );

        return props.reimbursementEligibleTransactions.filter(
            (transaction) => !memberIds.has(transaction.id),
        );
    }

    function ensureCloseForm(group) {
        if (!closeForms[group.id]) {
            closeForms[group.id] = {
                remainder_classification:
                    reimbursementNetState(group.net) === 'surplus'
                        ? 'income'
                        : 'expense',
                remainder_category_id: '',
            };
        }

        return closeForms[group.id];
    }

    function addEligibleToGroup(group) {
        let selectedId = Number(addToGroupSelections[group.id]);

        if (!selectedId) {
            return;
        }

        reimbursementActionKey.value = `add-eligible-${group.id}`;

        router.post(
            `/reconciliation/reimbursement-groups/${group.id}/transactions`,
            {
                transaction_ids: [selectedId],
            },
            {
                preserveScroll: true,
                onSuccess: () => {
                    addToGroupSelections[group.id] = '';
                },
                onFinish: () => {
                    reimbursementActionKey.value = null;
                },
            },
        );
    }

    function removeFromReimbursementGroup(group, transaction) {
        reimbursementActionKey.value = `remove-${group.id}-${transaction.id}`;

        router.delete(
            `/reconciliation/reimbursement-groups/${group.id}/transactions/${transaction.id}`,
            {
                preserveScroll: true,
                onFinish: () => {
                    reimbursementActionKey.value = null;
                },
            },
        );
    }

    function closeReimbursementGroup(group) {
        let form = ensureCloseForm(group);
        let netState = reimbursementNetState(group.net);

        reimbursementActionKey.value = `close-${group.id}`;

        router.post(
            `/reconciliation/reimbursement-groups/${group.id}/close`,
            {
                remainder_classification:
                    netState === 'surplus'
                        ? 'income'
                        : netState === 'under'
                          ? form.remainder_classification
                          : null,
                remainder_category_id:
                    netState === 'under'
                        ? Number(form.remainder_category_id)
                        : null,
            },
            {
                preserveScroll: true,
                onFinish: () => {
                    reimbursementActionKey.value = null;
                },
            },
        );
    }

    function reopenReimbursementGroup(group) {
        reimbursementActionKey.value = `reopen-${group.id}`;

        router.post(
            `/reconciliation/reimbursement-groups/${group.id}/reopen`,
            {},
            {
                preserveScroll: true,
                onFinish: () => {
                    reimbursementActionKey.value = null;
                },
            },
        );
    }

    function destroyReimbursementGroup(group) {
        if (
            !window.confirm(
                'Delete this reimbursement group and restore its transactions?',
            )
        ) {
            return;
        }

        reimbursementActionKey.value = `destroy-${group.id}`;

        router.delete(`/reconciliation/reimbursement-groups/${group.id}`, {
            preserveScroll: true,
            onFinish: () => {
                reimbursementActionKey.value = null;
            },
        });
    }
</script>

<template>
    <div class="space-y-8">
        <div v-if="openReimbursementGroups.length > 0" class="space-y-4">
            <div>
                <h2 class="text-base font-semibold">Open reimbursements</h2>
                <p class="text-sm text-neutral-600">
                    Grouped transactions stay out of category and income totals
                    until you close. Unreimbursed amounts go to an expense/bill
                    category; over-reimbursement surplus is booked as
                    uncategorized income.
                </p>
            </div>

            <ul class="space-y-4">
                <li
                    v-for="group in openReimbursementGroups"
                    :key="`open-reimb-${group.id}`"
                    class="space-y-3 rounded border px-4 py-3 text-sm"
                >
                    <div
                        class="flex flex-wrap items-start justify-between gap-4"
                    >
                        <div>
                            <p class="font-medium">
                                {{ group.name || 'Reimbursement group' }}
                            </p>
                            <p class="text-neutral-600">
                                Expenses
                                {{ formatMoney(group.expense_total) }} ·
                                Reimbursed
                                {{ formatMoney(group.reimbursement_total) }} ·
                                Net {{ formatMoney(group.net) }}
                            </p>
                        </div>
                        <div class="flex flex-wrap gap-2">
                            <button
                                type="button"
                                class="rounded border px-3 py-1.5 text-neutral-700 disabled:opacity-50"
                                :disabled="reimbursementActionKey !== null"
                                @click="destroyReimbursementGroup(group)"
                            >
                                Delete
                            </button>
                        </div>
                    </div>

                    <ul class="divide-y rounded border">
                        <li
                            v-for="leg in group.legs"
                            :key="`leg-${leg.id}`"
                            class="flex flex-wrap items-start justify-between gap-3 px-3 py-2"
                        >
                            <div>
                                <p class="font-medium">
                                    {{
                                        leg.role === 'expense'
                                            ? 'Expense'
                                            : 'Reimbursement'
                                    }}
                                    ·
                                    {{
                                        leg.transaction?.description ||
                                        'Transaction'
                                    }}
                                </p>
                                <p class="text-neutral-600">
                                    {{
                                        leg.transaction?.posted_at || 'No date'
                                    }}
                                    <span v-if="leg.transaction?.account">
                                        ·
                                        {{ accountLabel(leg.transaction) }}
                                    </span>
                                </p>
                            </div>
                            <div class="flex items-center gap-3">
                                <p class="font-medium">
                                    {{ formatMoney(leg.amount) }}
                                </p>
                                <button
                                    type="button"
                                    class="text-neutral-600 underline disabled:opacity-50"
                                    :disabled="reimbursementActionKey !== null"
                                    @click="
                                        removeFromReimbursementGroup(
                                            group,
                                            leg.transaction,
                                        )
                                    "
                                >
                                    Remove
                                </button>
                            </div>
                        </li>
                    </ul>

                    <div
                        class="flex flex-wrap items-end gap-2 rounded border bg-neutral-50 px-3 py-2"
                    >
                        <label class="block min-w-[16rem] flex-1 space-y-1">
                            <span class="text-neutral-600"
                                >Add transaction</span
                            >
                            <select
                                v-model="addToGroupSelections[group.id]"
                                class="w-full rounded border px-2 py-1.5"
                            >
                                <option value="">Select…</option>
                                <option
                                    v-for="transaction in eligibleForGroup(
                                        group,
                                    )"
                                    :key="`elig-${group.id}-${transaction.id}`"
                                    :value="transaction.id"
                                >
                                    {{ transaction.posted_at || 'No date' }} ·
                                    {{ transaction.description }} ·
                                    {{ formatMoney(transaction.amount) }}
                                </option>
                            </select>
                        </label>
                        <button
                            type="button"
                            class="rounded border px-3 py-1.5 text-neutral-700 disabled:opacity-50"
                            :disabled="
                                reimbursementActionKey !== null ||
                                !addToGroupSelections[group.id]
                            "
                            @click="addEligibleToGroup(group)"
                        >
                            Add
                        </button>
                    </div>

                    <div
                        class="grid gap-2 rounded border bg-neutral-50 px-3 py-2 sm:grid-cols-3"
                    >
                        <template
                            v-if="reimbursementNetState(group.net) === 'surplus'"
                        >
                            <div class="space-y-1 sm:col-span-2">
                                <p class="text-neutral-600">Remainder</p>
                                <p class="font-medium">
                                    Income surplus
                                    {{ formatMoney(Math.abs(group.net)) }}
                                    (uncategorized)
                                </p>
                            </div>
                        </template>
                        <template
                            v-else-if="
                                reimbursementNetState(group.net) === 'under'
                            "
                        >
                            <label class="block space-y-1">
                                <span class="text-neutral-600"
                                    >Remainder type</span
                                >
                                <select
                                    v-model="
                                        ensureCloseForm(group)
                                            .remainder_classification
                                    "
                                    class="w-full rounded border px-2 py-1.5"
                                >
                                    <option value="expense">Expense</option>
                                    <option value="bill">Bill</option>
                                </select>
                            </label>
                            <label class="block space-y-1">
                                <span class="text-neutral-600"
                                    >Remainder category</span
                                >
                                <select
                                    v-model="
                                        ensureCloseForm(group)
                                            .remainder_category_id
                                    "
                                    class="w-full rounded border px-2 py-1.5"
                                >
                                    <option value="">Select</option>
                                    <option
                                        v-for="category in categoriesForClassification(
                                            ensureCloseForm(group)
                                                .remainder_classification,
                                        )"
                                        :key="`close-cat-${group.id}-${category.id}`"
                                        :value="category.id"
                                    >
                                        {{ category.name }}
                                    </option>
                                </select>
                            </label>
                        </template>
                        <template v-else>
                            <div class="space-y-1 sm:col-span-2">
                                <p class="text-neutral-600">Remainder</p>
                                <p class="font-medium">
                                    None (fully reimbursed)
                                </p>
                            </div>
                        </template>
                        <div class="flex items-end">
                            <button
                                type="button"
                                class="w-full rounded bg-neutral-900 px-3 py-1.5 text-white disabled:opacity-50"
                                :disabled="
                                    reimbursementActionKey !== null ||
                                    (reimbursementNetState(group.net) ===
                                        'under' &&
                                        !ensureCloseForm(group)
                                            .remainder_category_id)
                                "
                                @click="closeReimbursementGroup(group)"
                            >
                                {{
                                    reimbursementActionKey ===
                                    `close-${group.id}`
                                        ? 'Closing…'
                                        : 'Close group'
                                }}
                            </button>
                        </div>
                    </div>
                </li>
            </ul>
        </div>

        <div
            v-if="closedReimbursementGroups.length > 0"
            class="space-y-4"
        >
            <div>
                <h2 class="text-base font-semibold">Closed reimbursements</h2>
                <p class="text-sm text-neutral-600">
                    Under-reimbursed remainder booked to a category; surplus
                    booked as income. Reopen to add late charges or payments.
                </p>
            </div>

            <ul class="space-y-3">
                <li
                    v-for="group in closedReimbursementGroups"
                    :key="`closed-reimb-${group.id}`"
                    class="flex flex-wrap items-start justify-between gap-4 rounded border px-4 py-3 text-sm"
                >
                    <div>
                        <p class="font-medium">
                            {{ group.name || 'Reimbursement group' }}
                        </p>
                        <p class="text-neutral-600">
                            Net {{ formatMoney(group.net) }}
                            <span
                                v-if="
                                    group.remainder_classification === 'income'
                                "
                            >
                                · income surplus
                            </span>
                            <span v-else-if="group.remainder_category">
                                · {{ group.remainder_category }}
                            </span>
                            <span v-else> · fully reimbursed</span>
                        </p>
                    </div>
                    <button
                        type="button"
                        class="rounded border px-3 py-1.5 text-neutral-700 disabled:opacity-50"
                        :disabled="reimbursementActionKey !== null"
                        @click="reopenReimbursementGroup(group)"
                    >
                        Reopen
                    </button>
                </li>
            </ul>
        </div>
    </div>
</template>
