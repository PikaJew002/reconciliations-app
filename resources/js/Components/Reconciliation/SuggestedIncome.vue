<script setup>
    import {
        accountLabel,
        formatMoney,
    } from '../../Composables/useReconciliationFormatting.js';
    import { router } from '@inertiajs/vue3';
    import { reactive, ref } from 'vue';

    let props = defineProps({
        suggestedIncome: {
            type: Array,
            default: () => [],
        },
        openReimbursementGroups: {
            type: Array,
            default: () => [],
        },
        incomeMatchModes: {
            type: Array,
            default: () => [
                'exact_description_and_amount',
                'description',
                'once',
            ],
        },
    });

    let incomeActionId = ref(null);
    let reimbursementActionKey = ref(null);
    let incomeForms = reactive({});

    function matchModeLabel(mode) {
        return (
            {
                exact_description_and_amount: 'Exact description + amount',
                amount_and_merchant: 'Amount + merchant',
                merchant: 'Merchant only',
                description: 'Description only',
                check_and_amount: 'Check + amount',
                description_prefix_and_amount: 'Starts with + amount',
                once: 'This transaction only',
            }[mode] ?? mode
        );
    }

    function ensureIncomeForm(transaction) {
        if (incomeForms[transaction.id]) {
            return incomeForms[transaction.id];
        }

        incomeForms[transaction.id] = {
            match_mode: 'exact_description_and_amount',
        };

        return incomeForms[transaction.id];
    }

    function confirmIncome(transaction) {
        let form = ensureIncomeForm(transaction);
        incomeActionId.value = `confirm-${transaction.id}`;
        router.post(
            `/reconciliation/transactions/${transaction.id}/confirm-income`,
            { match_mode: form.match_mode },
            {
                preserveScroll: true,
                onFinish: () => {
                    incomeActionId.value = null;
                },
            },
        );
    }

    function rejectIncome(transaction) {
        incomeActionId.value = `reject-${transaction.id}`;
        router.post(
            `/reconciliation/transactions/${transaction.id}/reject-income`,
            {},
            {
                preserveScroll: true,
                onFinish: () => {
                    incomeActionId.value = null;
                },
            },
        );
    }

    function createReimbursementGroup(transactionIds, name = null) {
        if (!transactionIds.length) {
            return;
        }

        reimbursementActionKey.value = 'create';

        router.post(
            '/reconciliation/reimbursement-groups',
            {
                transaction_ids: transactionIds,
                name,
            },
            {
                preserveScroll: true,
                onFinish: () => {
                    reimbursementActionKey.value = null;
                },
            },
        );
    }

    function useIncomeAsReimbursement(transaction) {
        if (props.openReimbursementGroups.length === 1) {
            reimbursementActionKey.value = `income-add-${transaction.id}`;

            router.post(
                `/reconciliation/reimbursement-groups/${props.openReimbursementGroups[0].id}/transactions`,
                {
                    transaction_ids: [transaction.id],
                },
                {
                    preserveScroll: true,
                    onFinish: () => {
                        reimbursementActionKey.value = null;
                    },
                },
            );

            return;
        }

        createReimbursementGroup([transaction.id], 'Reimbursement');
    }
</script>

<template>
    <div class="space-y-4">
        <div>
            <h2 class="text-base font-semibold">Suggested income</h2>
            <p class="text-sm text-neutral-600">
                Credits that look like income. Confirm to hide them and teach
                the app for next time.
            </p>
        </div>

        <p
            v-if="suggestedIncome.length === 0"
            class="text-sm text-neutral-600"
        >
            No suggested income.
        </p>

        <ul v-else class="space-y-3">
            <li
                v-for="transaction in suggestedIncome"
                :key="`income-${transaction.id}`"
                class="flex flex-wrap items-start justify-between gap-4 rounded border px-4 py-3 text-sm"
            >
                <div>
                    <p class="font-medium">
                        {{ accountLabel(transaction) }}
                    </p>
                    <p class="text-neutral-600">
                        {{ transaction.posted_at || 'No date' }} ·
                        {{ transaction.description }}
                    </p>
                    <p class="mt-1 text-neutral-600">
                        Suggested income
                        <span
                            v-if="
                                transaction.classification_confidence != null
                            "
                        >
                            ·
                            {{
                                Math.round(
                                    transaction.classification_confidence,
                                )
                            }}% confidence
                        </span>
                    </p>
                </div>
                <div class="space-y-2 text-right">
                    <p class="font-medium">
                        {{ formatMoney(transaction.amount) }}
                    </p>
                    <label class="block space-y-1 text-left">
                        <span class="text-xs text-neutral-600"
                            >Future match</span
                        >
                        <select
                            v-model="ensureIncomeForm(transaction).match_mode"
                            class="w-full rounded border px-2 py-1.5"
                            :disabled="incomeActionId !== null"
                        >
                            <option
                                v-for="mode in incomeMatchModes"
                                :key="mode"
                                :value="mode"
                            >
                                {{ matchModeLabel(mode) }}
                            </option>
                        </select>
                    </label>
                    <div class="flex flex-wrap justify-end gap-2">
                        <button
                            type="button"
                            class="rounded bg-neutral-900 px-3 py-1.5 text-white disabled:opacity-50"
                            :disabled="incomeActionId !== null"
                            @click="confirmIncome(transaction)"
                        >
                            {{
                                incomeActionId ===
                                `confirm-${transaction.id}`
                                    ? 'Confirming…'
                                    : 'Confirm income'
                            }}
                        </button>
                        <button
                            type="button"
                            class="rounded border px-3 py-1.5 text-neutral-700 disabled:opacity-50"
                            :disabled="
                                incomeActionId !== null ||
                                reimbursementActionKey !== null
                            "
                            @click="useIncomeAsReimbursement(transaction)"
                        >
                            Use as reimbursement
                        </button>
                        <button
                            type="button"
                            class="rounded border px-3 py-1.5 text-neutral-700 disabled:opacity-50"
                            :disabled="incomeActionId !== null"
                            @click="rejectIncome(transaction)"
                        >
                            {{
                                incomeActionId === `reject-${transaction.id}`
                                    ? 'Dismissing…'
                                    : 'Dismiss'
                            }}
                        </button>
                    </div>
                </div>
            </li>
        </ul>
    </div>
</template>
