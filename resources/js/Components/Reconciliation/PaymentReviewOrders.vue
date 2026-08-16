<script setup>
    import { formatMoney } from '../../Composables/useReconciliationFormatting.js';
    import { router } from '@inertiajs/vue3';
    import { reactive, ref, watch } from 'vue';

    let props = defineProps({
        paymentReviewOrders: {
            type: Array,
            required: true,
        },
    });

    let paymentForms = reactive({});
    let resolvingOrderId = ref(null);
    let removingPaymentKey = ref(null);

    function syncPaymentForms(orders) {
        for (let order of orders) {
            if (paymentForms[order.id]) {
                continue;
            }

            paymentForms[order.id] = order.payments.map((payment) => ({
                index: payment.index,
                amount: payment.amount ?? '',
                bank_transaction_id: '',
                kind: payment.kind,
            }));
        }
    }

    function paymentKindLabel(kind) {
        return (
            {
                card: 'Card',
                gift_card: 'Gift card',
                walmart_balance: 'Walmart Balance',
                unknown: 'Unknown',
            }[kind] || kind
        );
    }

    function canMarkAsGiftCard(payment) {
        return payment.kind === 'card' || payment.kind === 'unknown';
    }

    function paymentRequiresBankTransaction(order, paymentIndex) {
        let kind =
            paymentForms[order.id]?.[paymentIndex]?.kind ??
            order.payments[paymentIndex]?.kind;

        return kind === 'card' || kind === 'unknown';
    }

    function onGiftCardToggle(order, paymentIndex, event) {
        let row = paymentForms[order.id]?.[paymentIndex];
        let payment = order.payments[paymentIndex];

        if (!row || !payment) {
            return;
        }

        if (event.target.checked) {
            row.kind = 'gift_card';
            row.bank_transaction_id = '';
            return;
        }

        row.kind = payment.kind === 'gift_card' ? 'card' : payment.kind;
    }

    function amountsMatch(left, right) {
        return Math.abs(Number(left) - Number(right)) < 0.01;
    }

    function selectMatchingBankTransaction(order, paymentIndex, amount) {
        let rows = paymentForms[order.id];
        let row = rows?.[paymentIndex];
        let payment = order.payments[paymentIndex];

        if (!row || !paymentRequiresBankTransaction(order, paymentIndex)) {
            return;
        }

        let matches = payment.candidate_transactions.filter((tx) =>
            amountsMatch(Math.abs(Number(tx.amount)), amount),
        );

        if (matches.length === 1) {
            row.bank_transaction_id = matches[0].id;
            return;
        }

        // Clear a previous auto-selection if the amount no longer uniquely matches.
        if (row.bank_transaction_id) {
            let selected = payment.candidate_transactions.find(
                (tx) => tx.id === Number(row.bank_transaction_id),
            );

            if (
                !selected ||
                !amountsMatch(Math.abs(Number(selected.amount)), amount)
            ) {
                row.bank_transaction_id = '';
            }
        }
    }

    function onPaymentAmountBlur(order, paymentIndex) {
        let rows = paymentForms[order.id];
        let row = rows?.[paymentIndex];

        if (!row) {
            return;
        }

        let entered = Number(row.amount);

        if (!Number.isFinite(entered) || entered <= 0) {
            return;
        }

        row.amount = entered.toFixed(2);

        let otherIndexes = rows
            .map((_, index) => index)
            .filter((index) => index !== paymentIndex);

        if (otherIndexes.length === 1) {
            let otherIndex = otherIndexes[0];
            let remainder = Number(order.total) - entered;

            if (remainder > 0.009) {
                rows[otherIndex].amount = remainder.toFixed(2);
                selectMatchingBankTransaction(order, otherIndex, remainder);
            }
        }

        selectMatchingBankTransaction(order, paymentIndex, entered);
    }

    function onPaymentTransactionSelected(order, paymentIndex) {
        let rows = paymentForms[order.id];
        let row = rows?.[paymentIndex];
        let payment = order.payments[paymentIndex];

        if (!row || !payment) {
            return;
        }

        let selectedId = Number(row.bank_transaction_id);
        let selected = payment.candidate_transactions.find(
            (tx) => tx.id === selectedId,
        );

        if (!selected) {
            return;
        }

        row.amount = Math.abs(Number(selected.amount)).toFixed(2);

        let otherIndexes = rows
            .map((_, index) => index)
            .filter((index) => index !== paymentIndex);

        if (otherIndexes.length === 1) {
            let otherIndex = otherIndexes[0];
            let remainder =
                Number(order.total) - Math.abs(Number(selected.amount));
            rows[otherIndex].amount = remainder.toFixed(2);
        }
    }

    function resolvePayments(order) {
        let rows = paymentForms[order.id];

        if (!rows) {
            return;
        }

        resolvingOrderId.value = order.id;

        router.post(
            `/reconciliation/orders/${order.id}/resolve-payments`,
            {
                payments: rows.map((row) => ({
                    index: row.index,
                    amount: Number(row.amount),
                    bank_transaction_id: row.bank_transaction_id
                        ? Number(row.bank_transaction_id)
                        : null,
                    kind: row.kind,
                })),
            },
            {
                preserveScroll: true,
                onSuccess: () => {
                    delete paymentForms[order.id];
                },
                onFinish: () => {
                    resolvingOrderId.value = null;
                },
            },
        );
    }

    function removePayment(order, paymentIndex) {
        if (order.payments.length < 2) {
            return;
        }

        let key = `${order.id}-${paymentIndex}`;
        removingPaymentKey.value = key;

        router.delete(
            `/reconciliation/orders/${order.id}/payments/${paymentIndex}`,
            {
                preserveScroll: true,
                onSuccess: () => {
                    delete paymentForms[order.id];
                },
                onFinish: () => {
                    removingPaymentKey.value = null;
                },
            },
        );
    }

    watch(
        () => props.paymentReviewOrders,
        (orders) => syncPaymentForms(orders),
        { immediate: true },
    );
</script>

<template>
    <div class="space-y-4">
        <div>
            <h2 class="text-base font-semibold">Multi-payment orders</h2>
            <p class="text-sm text-neutral-600">
                Orders paid with more than one method (card + gift card /
                Walmart Balance). Remove a failed/duplicate attempt, or match
                the bank card charge, enter the other tender amount, then save.
            </p>
        </div>

        <ul class="space-y-4">
            <li
                v-for="order in paymentReviewOrders"
                :key="`payment-${order.id}`"
                class="space-y-3 rounded border px-4 py-3 text-sm"
            >
                <div class="flex items-start justify-between gap-4">
                    <div>
                        <p class="font-medium">
                            {{ order.merchant || 'Unknown merchant' }}
                            <span class="font-normal text-neutral-600"
                                >#{{ order.order_number }}</span
                            >
                        </p>
                        <p class="text-neutral-600">
                            {{ order.ordered_at || 'No date' }}
                        </p>
                    </div>
                    <p class="font-medium">
                        {{ formatMoney(order.total) }}
                    </p>
                </div>

                <p
                    v-if="!order.components_balanced"
                    class="rounded border border-amber-200 bg-amber-50 px-3 py-2 text-amber-900"
                >
                    Fix unbalanced components below first, then resolve
                    payments.
                </p>

                <form
                    class="space-y-3"
                    @submit.prevent="resolvePayments(order)"
                >
                    <div
                        v-for="(payment, paymentIndex) in order.payments"
                        :key="payment.index"
                        class="space-y-2 rounded border px-3 py-2"
                    >
                        <div class="flex items-center justify-between gap-3">
                            <div>
                                <p class="font-medium">
                                    {{ payment.ending }}
                                </p>
                                <p class="text-neutral-600">
                                    {{
                                        paymentKindLabel(
                                            paymentForms[order.id]?.[
                                                paymentIndex
                                            ]?.kind ?? payment.kind,
                                        )
                                    }}
                                </p>
                            </div>
                            <div class="flex flex-wrap items-center gap-3">
                                <label
                                    v-if="
                                        order.components_balanced &&
                                        paymentForms[order.id] &&
                                        canMarkAsGiftCard(payment)
                                    "
                                    class="flex items-center gap-2 text-neutral-700"
                                >
                                    <input
                                        type="checkbox"
                                        :checked="
                                            paymentForms[order.id][paymentIndex]
                                                .kind === 'gift_card'
                                        "
                                        @change="
                                            onGiftCardToggle(
                                                order,
                                                paymentIndex,
                                                $event,
                                            )
                                        "
                                    />
                                    <span>Mark as gift card</span>
                                </label>
                                <button
                                    v-if="order.payments.length > 1"
                                    type="button"
                                    class="text-xs text-red-700 underline disabled:opacity-50"
                                    :disabled="
                                        removingPaymentKey ===
                                        `${order.id}-${paymentIndex}`
                                    "
                                    @click="removePayment(order, paymentIndex)"
                                >
                                    Remove
                                </button>
                            </div>
                        </div>

                        <div
                            v-if="
                                order.components_balanced &&
                                paymentForms[order.id]
                            "
                            class="grid gap-3 sm:grid-cols-2"
                        >
                            <label class="block space-y-1">
                                <span class="text-neutral-600">Amount</span>
                                <input
                                    v-model="
                                        paymentForms[order.id][paymentIndex]
                                            .amount
                                    "
                                    type="number"
                                    step="0.01"
                                    min="0.01"
                                    class="w-full rounded border px-2"
                                    required
                                    @blur="
                                        onPaymentAmountBlur(order, paymentIndex)
                                    "
                                />
                            </label>

                            <label
                                v-if="
                                    paymentRequiresBankTransaction(
                                        order,
                                        paymentIndex,
                                    )
                                "
                                class="block space-y-1"
                            >
                                <span class="text-neutral-600"
                                    >Bank transaction</span
                                >
                                <select
                                    v-model="
                                        paymentForms[order.id][paymentIndex]
                                            .bank_transaction_id
                                    "
                                    class="w-full rounded border px-2"
                                    required
                                    @change="
                                        onPaymentTransactionSelected(
                                            order,
                                            paymentIndex,
                                        )
                                    "
                                >
                                    <option value="">
                                        Select transaction…
                                    </option>
                                    <option
                                        v-for="tx in payment.candidate_transactions"
                                        :key="tx.id"
                                        :value="tx.id"
                                    >
                                        {{ formatMoney(tx.amount) }} ·
                                        {{
                                            tx.transaction_date ||
                                            tx.posted_at ||
                                            'No date'
                                        }}
                                        <template v-if="tx.card_last_four">
                                            · card
                                            {{ tx.card_last_four }}</template
                                        >
                                    </option>
                                </select>
                            </label>

                            <p v-else class="self-end text-neutral-600">
                                Non-bank tender (no bank transaction required)
                            </p>
                        </div>
                    </div>

                    <button
                        v-if="
                            order.components_balanced && paymentForms[order.id]
                        "
                        type="submit"
                        class="btn rounded bg-brand hover:bg-brand-hover px-3 text-white disabled:opacity-50"
                        :disabled="resolvingOrderId === order.id"
                    >
                        {{
                            resolvingOrderId === order.id
                                ? 'Saving…'
                                : 'Resolve payments'
                        }}
                    </button>
                </form>
            </li>
        </ul>
    </div>
</template>
