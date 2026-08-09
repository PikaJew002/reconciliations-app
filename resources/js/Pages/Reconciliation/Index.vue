<script setup>
    import AuthenticatedLayout from '../../Layouts/AuthenticatedLayout.vue';
    import { router, useForm, usePage } from '@inertiajs/vue3';
    import {
        computed,
        onMounted,
        onUnmounted,
        reactive,
        ref,
        watch,
    } from 'vue';

    defineOptions({ layout: AuthenticatedLayout });

    let props = defineProps({
        summary: {
            type: Object,
            required: true,
        },
        unmatchedOrders: {
            type: Array,
            required: true,
        },
        unmatchedTransactions: {
            type: Array,
            required: true,
        },
        unbalancedOrders: {
            type: Array,
            required: true,
        },
        paymentReviewOrders: {
            type: Array,
            required: true,
        },
        suggestedTransfers: {
            type: Array,
            default: () => [],
        },
        suggestedIncome: {
            type: Array,
            default: () => [],
        },
        matchedPairs: {
            type: Array,
            required: true,
        },
        activeRun: {
            type: Object,
            default: null,
        },
    });

    let page = usePage();
    let flashSuccess = computed(() => page.props.flash?.success);
    let flashError = computed(() => page.props.flash?.error);
    let runForm = useForm({});
    let isRunInProgress = computed(() =>
        ['pending', 'processing'].includes(props.activeRun?.status),
    );
    let activeTab = ref(
        (props.summary.needs_review ?? 0) > 0 ? 'needs-review' : 'matched',
    );
    let unmatchedTransactionFilter = ref('all');
    let pollId = null;
    let componentForms = reactive({});
    let paymentForms = reactive({});
    let savingOrderId = ref(null);
    let resolvingOrderId = ref(null);
    let transferActionId = ref(null);
    let incomeActionId = ref(null);

    let tabs = computed(() => [
        {
            id: 'needs-review',
            label: 'Needs review',
            count: props.summary.needs_review ?? 0,
        },
        {
            id: 'matched',
            label: 'Matched pairs',
            count: props.summary.matched_pairs,
        },
        {
            id: 'unmatched-orders',
            label: 'Unmatched orders',
            count: props.summary.unmatched_orders,
        },
        {
            id: 'unmatched-transactions',
            label: 'Unmatched transactions',
            count: props.summary.unmatched_transactions,
        },
    ]);

    function isWalmartTransaction(transaction) {
        let merchant = (transaction.merchant || '').toLowerCase();
        let description = (transaction.description || '').toLowerCase();

        return (
            merchant.includes('walmart') ||
            description.includes('walmart') ||
            description.includes('wal-mart') ||
            description.includes('wm supercenter')
        );
    }

    function isAmazonTransaction(transaction) {
        let merchant = (transaction.merchant || '').toLowerCase();
        let description = (transaction.description || '').toLowerCase();

        return (
            merchant.includes('amazon') ||
            merchant.includes('amzn') ||
            description.includes('amazon') ||
            description.includes('amzn')
        );
    }

    function isTransferTransaction(transaction) {
        return (transaction.description || '')
            .toLowerCase()
            .includes('transfer');
    }

    function unmatchedTransactionCategory(transaction) {
        if (isWalmartTransaction(transaction)) {
            return 'walmart';
        }

        if (isAmazonTransaction(transaction)) {
            return 'amazon';
        }

        if (!transaction.merchant && isTransferTransaction(transaction)) {
            return 'untagged-transfer';
        }

        if (!transaction.merchant) {
            return 'untagged-other';
        }

        return 'other';
    }

    function unmatchedTransactionTitle(transaction) {
        if (transaction.merchant) {
            return transaction.merchant;
        }

        if (isTransferTransaction(transaction)) {
            return 'Untagged transaction (transfer)';
        }

        return 'Untagged transaction (Other)';
    }

    let unmatchedTransactionFilters = computed(() => {
        let counts = {
            all: props.unmatchedTransactions.length,
            walmart: 0,
            amazon: 0,
            'untagged-transfer': 0,
            'untagged-other': 0,
        };

        for (let transaction of props.unmatchedTransactions) {
            let category = unmatchedTransactionCategory(transaction);

            if (category in counts) {
                counts[category] += 1;
            }
        }

        return [
            { id: 'all', label: 'All', count: counts.all },
            { id: 'walmart', label: 'Walmart', count: counts.walmart },
            { id: 'amazon', label: 'Amazon', count: counts.amazon },
            {
                id: 'untagged-transfer',
                label: 'Untagged (transfer)',
                count: counts['untagged-transfer'],
            },
            {
                id: 'untagged-other',
                label: 'Untagged (Other)',
                count: counts['untagged-other'],
            },
        ];
    });

    let filteredUnmatchedTransactions = computed(() => {
        if (unmatchedTransactionFilter.value === 'all') {
            return props.unmatchedTransactions;
        }

        return props.unmatchedTransactions.filter(
            (transaction) =>
                unmatchedTransactionCategory(transaction) ===
                unmatchedTransactionFilter.value,
        );
    });

    function formatMoney(amount) {
        let value = Number(amount ?? 0);
        return value.toLocaleString('en-US', {
            style: 'currency',
            currency: 'USD',
        });
    }

    function syncComponentForms(orders) {
        for (let order of orders) {
            if (componentForms[order.id]) {
                continue;
            }

            componentForms[order.id] = {
                type: order.gap > 0 ? 'delivery' : 'other',
                description: order.gap > 0 ? 'Fast delivery fee' : 'Adjustment',
                amount: Number(order.gap.toFixed(2)),
            };
        }
    }

    function addComponent(order) {
        let form = componentForms[order.id];

        if (!form) {
            return;
        }

        savingOrderId.value = order.id;

        router.post(`/reconciliation/orders/${order.id}/components`, form, {
            preserveScroll: true,
            onSuccess: () => {
                delete componentForms[order.id];
                activeTab.value = 'needs-review';
            },
            onFinish: () => {
                savingOrderId.value = null;
            },
        });
    }

    function deleteComponent(order, component) {
        if (!component.can_delete) {
            return;
        }

        router.delete(
            `/reconciliation/orders/${order.id}/components/${component.id}`,
            {
                preserveScroll: true,
            },
        );
    }

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
                    activeTab.value = 'needs-review';
                },
                onFinish: () => {
                    resolvingOrderId.value = null;
                },
            },
        );
    }

    watch(
        () => props.unbalancedOrders,
        (orders) => syncComponentForms(orders),
        { immediate: true },
    );

    watch(
        () => props.paymentReviewOrders,
        (orders) => syncPaymentForms(orders),
        { immediate: true },
    );

    function confirmTransfer(link) {
        transferActionId.value = `confirm-${link.id}`;
        router.post(`/reconciliation/transfers/${link.id}/confirm`, {}, {
            preserveScroll: true,
            onFinish: () => {
                transferActionId.value = null;
            },
        });
    }

    function rejectTransfer(link) {
        transferActionId.value = `reject-${link.id}`;
        router.post(`/reconciliation/transfers/${link.id}/reject`, {}, {
            preserveScroll: true,
            onFinish: () => {
                transferActionId.value = null;
            },
        });
    }

    function confirmIncome(transaction) {
        incomeActionId.value = `confirm-${transaction.id}`;
        router.post(
            `/reconciliation/transactions/${transaction.id}/confirm-income`,
            {},
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

    function accountLabel(transaction) {
        if (!transaction?.account) {
            return 'Account';
        }

        if (transaction.account_last_four) {
            return `${transaction.account} ····${transaction.account_last_four}`;
        }

        return transaction.account;
    }

    function runReconciliation() {
        runForm.post('/reconciliation/run', {
            preserveScroll: true,
            onSuccess: () => startPolling(),
        });
    }

    function startPolling() {
        if (pollId || !isRunInProgress.value) {
            return;
        }

        pollId = window.setInterval(() => {
            router.reload({
                only: [
                    'summary',
                    'unmatchedOrders',
                    'unmatchedTransactions',
                    'suggestedTransfers',
                    'suggestedIncome',
                    'unbalancedOrders',
                    'paymentReviewOrders',
                    'matchedPairs',
                    'activeRun',
                ],
                preserveScroll: true,
                onSuccess: () => {
                    if (!isRunInProgress.value && pollId) {
                        stopPolling();
                    }
                },
            });
        }, 2000);
    }

    function stopPolling() {
        if (pollId) {
            window.clearInterval(pollId);
            pollId = null;
        }
    }

    onMounted(() => {
        if (isRunInProgress.value) {
            startPolling();
        }
    });

    watch(isRunInProgress, (inProgress) => {
        if (inProgress) {
            startPolling();
            return;
        }

        stopPolling();
    });

    onUnmounted(() => {
        stopPolling();
    });
</script>

<template>
    <div class="space-y-8">
        <div class="flex items-center justify-between gap-4">
            <div>
                <h1 class="text-2xl font-semibold">Reconciliation</h1>
                <p class="text-sm text-neutral-600">
                    Review matched and unmatched activity.
                </p>
            </div>

            <button
                type="button"
                class="rounded bg-neutral-900 px-4 py-2 text-sm text-white disabled:opacity-50"
                :disabled="runForm.processing || isRunInProgress"
                @click="runReconciliation"
            >
                {{
                    isRunInProgress || runForm.processing
                        ? 'Running…'
                        : 'Run reconciliation'
                }}
            </button>
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

        <p
            v-if="isRunInProgress"
            class="rounded border border-amber-200 bg-amber-50 px-3 py-2 text-sm text-amber-900"
        >
            Reconciliation {{ activeRun.status }}… this page updates
            automatically.
        </p>

        <p
            v-else-if="activeRun?.status === 'completed'"
            class="rounded border border-green-200 bg-green-50 px-3 py-2 text-sm text-green-800"
        >
            Reconciliation finished. Confirmed
            {{ activeRun.metadata?.credit_card_payments_confirmed ?? 0 }} card
            payments and
            {{ activeRun.metadata?.transfers_confirmed ?? 0 }} transfers,
            suggested
            {{ activeRun.metadata?.credit_card_payments_suggested ?? 0 }} card
            payments,
            {{ activeRun.metadata?.transfers_suggested ?? 0 }} transfers and
            {{ activeRun.metadata?.income_suggested ?? 0 }} income, matched
            {{ activeRun.metadata?.merchants_matched ?? 0 }} merchants,
            {{ activeRun.metadata?.transactions_matched ?? 0 }} order
            transactions, and
            {{ activeRun.metadata?.synthetic_matched ?? 0 }} synthetic bank
            spends.
        </p>

        <p
            v-else-if="activeRun?.status === 'failed'"
            class="rounded border border-red-200 bg-red-50 px-3 py-2 text-sm text-red-800"
        >
            Reconciliation failed{{
                activeRun.error_message ? `: ${activeRun.error_message}` : '.'
            }}
        </p>

        <p class="text-sm text-neutral-600">
            Re-runs merchant matching, Walmart order matching, and synthetic
            bank-spend reconciliation on data you have already imported.
        </p>

        <dl class="grid grid-cols-2 gap-3 text-sm sm:grid-cols-3">
            <div class="rounded border px-3 py-2">
                <dt class="text-neutral-600">Needs review</dt>
                <dd class="text-lg font-medium">
                    {{ summary.needs_review ?? 0 }}
                </dd>
            </div>
            <div class="rounded border px-3 py-2">
                <dt class="text-neutral-600">Unmatched orders</dt>
                <dd class="text-lg font-medium">
                    {{ summary.unmatched_orders }}
                </dd>
            </div>
            <div class="rounded border px-3 py-2">
                <dt class="text-neutral-600">Reconciled orders</dt>
                <dd class="text-lg font-medium">
                    {{ summary.reconciled_orders }}
                </dd>
            </div>
            <div class="rounded border px-3 py-2">
                <dt class="text-neutral-600">Matched pairs</dt>
                <dd class="text-lg font-medium">{{ summary.matched_pairs }}</dd>
            </div>
            <div class="rounded border px-3 py-2">
                <dt class="text-neutral-600">Unmatched transactions</dt>
                <dd class="text-lg font-medium">
                    {{ summary.unmatched_transactions }}
                </dd>
            </div>
            <div class="rounded border px-3 py-2">
                <dt class="text-neutral-600">Partial transactions</dt>
                <dd class="text-lg font-medium">
                    {{ summary.partial_transactions }}
                </dd>
            </div>
        </dl>

        <div class="space-y-4">
            <div class="flex flex-wrap gap-2 border-b pb-2">
                <button
                    v-for="tab in tabs"
                    :key="tab.id"
                    type="button"
                    class="rounded px-3 py-1.5 text-sm"
                    :class="
                        activeTab === tab.id
                            ? 'bg-neutral-900 text-white'
                            : 'text-neutral-700 hover:bg-neutral-100'
                    "
                    @click="activeTab = tab.id"
                >
                    {{ tab.label }}
                    <span class="ml-1 opacity-80">({{ tab.count }})</span>
                </button>
            </div>

            <section v-if="activeTab === 'needs-review'" class="space-y-8">
                <div class="space-y-4">
                    <div>
                        <h2 class="text-base font-semibold">
                            Suggested transfers
                        </h2>
                        <p class="text-sm text-neutral-600">
                            Internal account transfers. Confirm to hide both
                            sides from expense tracking.
                        </p>
                    </div>

                    <p
                        v-if="suggestedTransfers.length === 0"
                        class="text-sm text-neutral-600"
                    >
                        No suggested transfers.
                    </p>

                    <ul v-else class="space-y-3">
                        <li
                            v-for="link in suggestedTransfers"
                            :key="`transfer-${link.id}`"
                            class="space-y-3 rounded border px-4 py-3 text-sm"
                        >
                            <div
                                class="flex flex-wrap items-start justify-between gap-4"
                            >
                                <div class="space-y-2">
                                    <div>
                                        <p class="font-medium">
                                            From
                                            {{ accountLabel(link.debit) }}
                                        </p>
                                        <p class="text-neutral-600">
                                            {{
                                                link.debit.posted_at ||
                                                'No date'
                                            }}
                                            · {{ link.debit.description }}
                                        </p>
                                    </div>
                                    <div>
                                        <p class="font-medium">
                                            To
                                            {{ accountLabel(link.credit) }}
                                        </p>
                                        <p class="text-neutral-600">
                                            {{
                                                link.credit.posted_at ||
                                                'No date'
                                            }}
                                            · {{ link.credit.description }}
                                        </p>
                                    </div>
                                </div>
                                <div class="text-right">
                                    <p class="font-medium">
                                        {{
                                            formatMoney(
                                                Math.abs(link.debit.amount),
                                            )
                                        }}
                                    </p>
                                    <p
                                        v-if="link.match_confidence != null"
                                        class="text-neutral-600"
                                    >
                                        {{
                                            Math.round(link.match_confidence)
                                        }}% confidence
                                    </p>
                                </div>
                            </div>

                            <div class="flex flex-wrap gap-2">
                                <button
                                    type="button"
                                    class="rounded bg-neutral-900 px-3 py-1.5 text-white disabled:opacity-50"
                                    :disabled="transferActionId !== null"
                                    @click="confirmTransfer(link)"
                                >
                                    {{
                                        transferActionId ===
                                        `confirm-${link.id}`
                                            ? 'Confirming…'
                                            : 'Confirm transfer'
                                    }}
                                </button>
                                <button
                                    type="button"
                                    class="rounded border px-3 py-1.5 text-neutral-700 disabled:opacity-50"
                                    :disabled="transferActionId !== null"
                                    @click="rejectTransfer(link)"
                                >
                                    {{
                                        transferActionId ===
                                        `reject-${link.id}`
                                            ? 'Dismissing…'
                                            : 'Dismiss'
                                    }}
                                </button>
                            </div>
                        </li>
                    </ul>
                </div>

                <div class="space-y-4">
                    <div>
                        <h2 class="text-base font-semibold">
                            Suggested income
                        </h2>
                        <p class="text-sm text-neutral-600">
                            Credits that look like income. Confirm to hide them
                            and teach the app for next time.
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
                                    {{
                                        transaction.posted_at || 'No date'
                                    }}
                                    · {{ transaction.description }}
                                </p>
                                <p class="mt-1 text-neutral-600">
                                    Suggested income
                                    <span
                                        v-if="
                                            transaction.classification_confidence !=
                                            null
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
                                        :disabled="incomeActionId !== null"
                                        @click="rejectIncome(transaction)"
                                    >
                                        {{
                                            incomeActionId ===
                                            `reject-${transaction.id}`
                                                ? 'Dismissing…'
                                                : 'Dismiss'
                                        }}
                                    </button>
                                </div>
                            </div>
                        </li>
                    </ul>
                </div>

                <div class="space-y-4">
                    <div>
                        <h2 class="text-base font-semibold">
                            Multi-payment orders
                        </h2>
                        <p class="text-sm text-neutral-600">
                            Orders paid with more than one method (card + gift
                            card / Walmart Balance). Match the bank card charge,
                            enter the other tender amount, then save.
                        </p>
                    </div>

                    <p
                        v-if="paymentReviewOrders.length === 0"
                        class="text-sm text-neutral-600"
                    >
                        No multi-payment orders need review.
                    </p>

                    <ul v-else class="space-y-4">
                        <li
                            v-for="order in paymentReviewOrders"
                            :key="`payment-${order.id}`"
                            class="space-y-3 rounded border px-4 py-3 text-sm"
                        >
                            <div class="flex items-start justify-between gap-4">
                                <div>
                                    <p class="font-medium">
                                        {{
                                            order.merchant || 'Unknown merchant'
                                        }}
                                        <span
                                            class="font-normal text-neutral-600"
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
                                Fix unbalanced components below first, then
                                resolve payments.
                            </p>

                            <form
                                v-if="
                                    paymentForms[order.id] &&
                                    order.components_balanced
                                "
                                class="space-y-3"
                                @submit.prevent="resolvePayments(order)"
                            >
                                <div
                                    v-for="(
                                        payment, paymentIndex
                                    ) in order.payments"
                                    :key="payment.index"
                                    class="space-y-2 rounded border px-3 py-2"
                                >
                                    <div
                                        class="flex items-center justify-between gap-3"
                                    >
                                        <div>
                                            <p class="font-medium">
                                                {{ payment.ending }}
                                            </p>
                                            <p class="text-neutral-600">
                                                {{
                                                    paymentKindLabel(
                                                        paymentForms[order.id][
                                                            paymentIndex
                                                        ].kind,
                                                    )
                                                }}
                                            </p>
                                        </div>
                                        <label
                                            v-if="canMarkAsGiftCard(payment)"
                                            class="flex items-center gap-2 text-neutral-700"
                                        >
                                            <input
                                                type="checkbox"
                                                :checked="
                                                    paymentForms[order.id][
                                                        paymentIndex
                                                    ].kind === 'gift_card'
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
                                    </div>

                                    <div class="grid gap-3 sm:grid-cols-2">
                                        <label class="block space-y-1">
                                            <span class="text-neutral-600"
                                                >Amount</span
                                            >
                                            <input
                                                v-model="
                                                    paymentForms[order.id][
                                                        paymentIndex
                                                    ].amount
                                                "
                                                type="number"
                                                step="0.01"
                                                min="0.01"
                                                class="w-full rounded border px-2 py-1.5"
                                                required
                                                @blur="
                                                    onPaymentAmountBlur(
                                                        order,
                                                        paymentIndex,
                                                    )
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
                                                    paymentForms[order.id][
                                                        paymentIndex
                                                    ].bank_transaction_id
                                                "
                                                class="w-full rounded border px-2 py-1.5"
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
                                                    {{ formatMoney(tx.amount) }}
                                                    ·
                                                    {{
                                                        tx.transaction_date ||
                                                        tx.posted_at ||
                                                        'No date'
                                                    }}
                                                    <template
                                                        v-if="tx.card_last_four"
                                                    >
                                                        · card
                                                        {{
                                                            tx.card_last_four
                                                        }}</template
                                                    >
                                                </option>
                                            </select>
                                        </label>

                                        <p
                                            v-else
                                            class="self-end text-neutral-600"
                                        >
                                            Non-bank tender (no bank transaction
                                            required)
                                        </p>
                                    </div>
                                </div>

                                <button
                                    type="submit"
                                    class="rounded bg-neutral-900 px-3 py-1.5 text-white disabled:opacity-50"
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

                <div class="space-y-4">
                    <div>
                        <h2 class="text-base font-semibold">
                            Unbalanced components
                        </h2>
                        <p class="text-sm text-neutral-600">
                            Orders whose components do not add up to the order
                            total. Add the missing fee (for example Fast
                            delivery) or remove a bad component, then re-run
                            reconciliation.
                        </p>
                    </div>

                    <p
                        v-if="unbalancedOrders.length === 0"
                        class="text-sm text-neutral-600"
                    >
                        No unbalanced orders.
                    </p>

                    <ul v-else class="space-y-4">
                        <li
                            v-for="order in unbalancedOrders"
                            :key="order.id"
                            class="space-y-3 rounded border px-4 py-3 text-sm"
                        >
                            <div class="flex items-start justify-between gap-4">
                                <div>
                                    <p class="font-medium">
                                        {{
                                            order.merchant || 'Unknown merchant'
                                        }}
                                        <span
                                            class="font-normal text-neutral-600"
                                            >#{{ order.order_number }}</span
                                        >
                                    </p>
                                    <p class="text-neutral-600">
                                        {{ order.ordered_at || 'No date' }}
                                        <span v-if="order.payment_last_four">
                                            · card
                                            {{ order.payment_last_four }}</span
                                        >
                                    </p>
                                </div>
                                <div class="text-right">
                                    <p class="font-medium">
                                        {{ formatMoney(order.total) }} total
                                    </p>
                                    <p class="text-neutral-600">
                                        Components
                                        {{ formatMoney(order.component_sum) }}
                                    </p>
                                    <p class="font-medium text-amber-800">
                                        Gap {{ formatMoney(order.gap) }}
                                    </p>
                                </div>
                            </div>

                            <ul class="divide-y rounded border">
                                <li
                                    v-for="component in order.components"
                                    :key="component.id"
                                    class="flex items-center justify-between gap-3 px-3 py-2"
                                >
                                    <div>
                                        <p class="font-medium">
                                            {{ component.description }}
                                        </p>
                                        <p class="text-neutral-600">
                                            {{ component.type }}
                                            <span
                                                v-if="
                                                    component.is_user_modified
                                                "
                                            >
                                                · manual</span
                                            >
                                        </p>
                                    </div>
                                    <div class="flex items-center gap-3">
                                        <p class="font-medium">
                                            {{ formatMoney(component.amount) }}
                                        </p>
                                        <button
                                            v-if="component.can_delete"
                                            type="button"
                                            class="text-xs text-red-700 underline"
                                            @click="
                                                deleteComponent(
                                                    order,
                                                    component,
                                                )
                                            "
                                        >
                                            Remove
                                        </button>
                                    </div>
                                </li>
                            </ul>

                            <form
                                v-if="componentForms[order.id]"
                                class="grid gap-3 sm:grid-cols-4"
                                @submit.prevent="addComponent(order)"
                            >
                                <label class="block space-y-1 sm:col-span-1">
                                    <span class="text-neutral-600">Type</span>
                                    <select
                                        v-model="componentForms[order.id].type"
                                        class="w-full rounded border px-2 py-1.5"
                                    >
                                        <option value="delivery">
                                            Delivery
                                        </option>
                                        <option value="fee">Fee</option>
                                        <option value="tip">Tip</option>
                                        <option value="tax">Tax</option>
                                        <option value="other">Other</option>
                                    </select>
                                </label>

                                <label class="block space-y-1 sm:col-span-2">
                                    <span class="text-neutral-600"
                                        >Description</span
                                    >
                                    <input
                                        v-model="
                                            componentForms[order.id].description
                                        "
                                        type="text"
                                        class="w-full rounded border px-2 py-1.5"
                                        required
                                    />
                                </label>

                                <label class="block space-y-1 sm:col-span-1">
                                    <span class="text-neutral-600">Amount</span>
                                    <input
                                        v-model="
                                            componentForms[order.id].amount
                                        "
                                        type="number"
                                        step="0.01"
                                        class="w-full rounded border px-2 py-1.5"
                                        required
                                    />
                                </label>

                                <div class="sm:col-span-4">
                                    <button
                                        type="submit"
                                        class="rounded bg-neutral-900 px-3 py-1.5 text-white disabled:opacity-50"
                                        :disabled="savingOrderId === order.id"
                                    >
                                        {{
                                            savingOrderId === order.id
                                                ? 'Saving…'
                                                : 'Add component'
                                        }}
                                    </button>
                                </div>
                            </form>
                        </li>
                    </ul>
                </div>
            </section>

            <section v-else-if="activeTab === 'matched'" class="space-y-3">
                <p
                    v-if="matchedPairs.length === 0"
                    class="text-sm text-neutral-600"
                >
                    No matched pairs yet.
                </p>

                <ul v-else class="divide-y rounded border">
                    <li
                        v-for="pair in matchedPairs"
                        :key="`${pair.transaction.id}-${pair.order.id}`"
                        class="space-y-2 px-4 py-3 text-sm"
                    >
                        <div class="flex items-start justify-between gap-4">
                            <div>
                                <p class="font-medium">
                                    {{
                                        pair.transaction.merchant ||
                                        'Bank transaction'
                                    }}
                                </p>
                                <p class="text-neutral-600">
                                    {{
                                        pair.transaction.transaction_date ||
                                        pair.transaction.posted_at ||
                                        'No date'
                                    }}
                                    · {{ pair.transaction.description }}
                                    <span
                                        v-if="pair.transaction.card_last_four"
                                    >
                                        · card
                                        {{ pair.transaction.card_last_four }}
                                    </span>
                                </p>
                            </div>
                            <div class="text-right">
                                <p class="font-medium">
                                    {{ formatMoney(pair.transaction.amount) }}
                                </p>
                                <p class="text-neutral-600">
                                    {{ pair.transaction.status }}
                                </p>
                            </div>
                        </div>

                        <div
                            class="flex items-start justify-between gap-4 border-l-2 border-neutral-200 pl-3"
                        >
                            <div>
                                <p class="font-medium">
                                    {{
                                        pair.order.merchant ||
                                        'Unknown merchant'
                                    }}
                                    <span class="font-normal text-neutral-600"
                                        >#{{ pair.order.order_number }}</span
                                    >
                                </p>
                                <p class="text-neutral-600">
                                    {{ pair.order.ordered_at || 'No date' }}
                                    <span v-if="pair.order.payment_last_four">
                                        · card
                                        {{ pair.order.payment_last_four }}
                                    </span>
                                </p>
                            </div>
                            <div class="text-right">
                                <p class="font-medium">
                                    {{ formatMoney(pair.order.total) }}
                                </p>
                                <p class="text-neutral-600">
                                    {{ pair.order.status }}
                                </p>
                            </div>
                        </div>

                        <p class="text-neutral-600">
                            Allocated {{ formatMoney(pair.allocated_amount) }}
                        </p>
                    </li>
                </ul>
            </section>

            <section
                v-else-if="activeTab === 'unmatched-orders'"
                class="space-y-3"
            >
                <p
                    v-if="unmatchedOrders.length === 0"
                    class="text-sm text-neutral-600"
                >
                    No unmatched orders.
                </p>

                <ul v-else class="divide-y rounded border">
                    <li
                        v-for="order in unmatchedOrders"
                        :key="order.id"
                        class="flex items-start justify-between gap-4 px-4 py-3 text-sm"
                    >
                        <div>
                            <p class="font-medium">
                                {{ order.merchant || 'Unknown merchant' }}
                                <span class="font-normal text-neutral-600"
                                    >#{{ order.order_number }}</span
                                >
                            </p>
                            <p class="text-neutral-600">
                                {{ order.ordered_at || 'No date' }}
                                <span v-if="order.payment_last_four">
                                    · card {{ order.payment_last_four }}</span
                                >
                            </p>
                        </div>
                        <div class="text-right">
                            <p class="font-medium">
                                {{ formatMoney(order.total) }}
                            </p>
                            <p class="text-neutral-600">{{ order.status }}</p>
                        </div>
                    </li>
                </ul>
            </section>

            <section v-else class="space-y-3">
                <div
                    v-if="unmatchedTransactions.length > 0"
                    class="flex flex-wrap gap-2"
                >
                    <button
                        v-for="filter in unmatchedTransactionFilters"
                        :key="filter.id"
                        type="button"
                        class="rounded px-3 py-1.5 text-sm"
                        :class="
                            unmatchedTransactionFilter === filter.id
                                ? 'bg-neutral-900 text-white'
                                : 'border text-neutral-700 hover:bg-neutral-100'
                        "
                        @click="unmatchedTransactionFilter = filter.id"
                    >
                        {{ filter.label }}
                        <span class="ml-1 opacity-80"
                            >({{ filter.count }})</span
                        >
                    </button>
                </div>

                <p
                    v-if="unmatchedTransactions.length === 0"
                    class="text-sm text-neutral-600"
                >
                    No unmatched transactions.
                </p>

                <p
                    v-else-if="filteredUnmatchedTransactions.length === 0"
                    class="text-sm text-neutral-600"
                >
                    No unmatched transactions in this filter.
                </p>

                <ul v-else class="divide-y rounded border">
                    <li
                        v-for="transaction in filteredUnmatchedTransactions"
                        :key="transaction.id"
                        class="flex items-start justify-between gap-4 px-4 py-3 text-sm"
                    >
                        <div>
                            <p class="font-medium">
                                {{ unmatchedTransactionTitle(transaction) }}
                            </p>
                            <p class="text-neutral-600">
                                {{
                                    transaction.transaction_date ||
                                    transaction.posted_at ||
                                    'No date'
                                }}
                                · {{ transaction.description }}
                                <span v-if="transaction.card_last_four">
                                    · card {{ transaction.card_last_four }}
                                </span>
                            </p>
                        </div>
                        <div class="text-right">
                            <p class="font-medium">
                                {{ formatMoney(transaction.amount) }}
                            </p>
                            <p class="text-neutral-600">
                                {{ transaction.status }}
                            </p>
                        </div>
                    </li>
                </ul>
            </section>
        </div>
    </div>
</template>
