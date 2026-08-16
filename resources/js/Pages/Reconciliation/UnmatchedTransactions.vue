<script setup>
    import ReconciliationShell from '../../Components/Reconciliation/ReconciliationShell.vue';
    import UnmatchedTransactionsList from '../../Components/Reconciliation/UnmatchedTransactionsList.vue';
    import AuthenticatedLayout from '../../Layouts/AuthenticatedLayout.vue';
    import { formatMoney } from '../../Composables/useReconciliationFormatting.js';
    import { Link, router } from '@inertiajs/vue3';
    import { computed, ref } from 'vue';

    defineOptions({ layout: AuthenticatedLayout });

    let props = defineProps({
        summary: {
            type: Object,
            required: true,
        },
        unmatchedTransactions: {
            type: Array,
            required: true,
        },
        openReimbursementGroups: {
            type: Array,
            default: () => [],
        },
        categories: {
            type: Array,
            default: () => [],
        },
        matchModes: {
            type: Array,
            default: () => [],
        },
        activeRun: {
            type: Object,
            default: null,
        },
        activeCategorizeRuns: {
            type: Array,
            default: () => [],
        },
    });

    const unmatchedTransactionsReloadOnly = [
        'unmatchedTransactions',
        'openReimbursementGroups',
    ];

    let selectedTransactionIds = ref([]);
    let reimbursementActionKey = ref(null);
    let targetOpenGroupId = ref('');

    let unlabeledVenmoCount = computed(
        () =>
            props.unmatchedTransactions.filter(
                (transaction) =>
                    String(transaction.description || '')
                        .toLowerCase()
                        .includes('venmo') && !transaction.venmo_summary,
            ).length,
    );

    function isTransactionSelected(transactionId) {
        return selectedTransactionIds.value.includes(transactionId);
    }

    function toggleTransactionSelection(transactionId) {
        if (isTransactionSelected(transactionId)) {
            selectedTransactionIds.value = selectedTransactionIds.value.filter(
                (id) => id !== transactionId,
            );
            return;
        }

        selectedTransactionIds.value = [
            ...selectedTransactionIds.value,
            transactionId,
        ];
    }

    function clearTransactionSelection() {
        selectedTransactionIds.value = [];
    }

    function createReimbursementGroup() {
        if (!selectedTransactionIds.value.length) {
            return;
        }

        reimbursementActionKey.value = 'create';

        router.post(
            '/reconciliation/reimbursement-groups',
            {
                transaction_ids: selectedTransactionIds.value,
                name: null,
            },
            {
                preserveScroll: true,
                onSuccess: () => {
                    clearTransactionSelection();
                },
                onFinish: () => {
                    reimbursementActionKey.value = null;
                },
            },
        );
    }

    function addSelectedToOpenGroup() {
        let groupId = Number(targetOpenGroupId.value);

        if (!groupId || !selectedTransactionIds.value.length) {
            return;
        }

        reimbursementActionKey.value = `add-${groupId}`;

        router.post(
            `/reconciliation/reimbursement-groups/${groupId}/transactions`,
            {
                transaction_ids: selectedTransactionIds.value,
            },
            {
                preserveScroll: true,
                onSuccess: () => {
                    clearTransactionSelection();
                },
                onFinish: () => {
                    reimbursementActionKey.value = null;
                },
            },
        );
    }
</script>

<template>
    <ReconciliationShell
        :summary="summary"
        :active-run="activeRun"
        :active-categorize-runs="activeCategorizeRuns"
        active-tab="unmatched-transactions"
        :reload-only="unmatchedTransactionsReloadOnly"
    >
        <div class="space-y-4">
            <p
                v-if="unlabeledVenmoCount > 0"
                class="rounded border px-3 py-2 text-sm text-neutral-700"
            >
                {{ unlabeledVenmoCount }} unmatched
                {{ unlabeledVenmoCount === 1 ? 'line looks' : 'lines look' }}
                like Venmo with no statement details.
                <Link href="/venmo/imports" class="underline">
                    Import a Venmo statement
                </Link>
                to label who and why.
            </p>
            <UnmatchedTransactionsList
                :unmatched-transactions="unmatchedTransactions"
                :categories="categories"
                :match-modes="matchModes"
                :selected-transaction-ids="selectedTransactionIds"
                @toggle-selection="toggleTransactionSelection"
            >
            <div
                v-if="selectedTransactionIds.length > 0"
                class="flex flex-wrap items-center gap-2 rounded border bg-neutral-50 px-3 py-2 text-sm"
            >
                <span class="font-medium">
                    {{ selectedTransactionIds.length }} selected
                </span>
                <button
                    type="button"
                    class="btn rounded bg-brand hover:bg-brand-hover px-3 text-white disabled:opacity-50"
                    :disabled="reimbursementActionKey !== null"
                    @click="createReimbursementGroup()"
                >
                    {{
                        reimbursementActionKey === 'create'
                            ? 'Creating…'
                            : 'Create reimbursement group'
                    }}
                </button>
                <template v-if="openReimbursementGroups.length > 0">
                    <select
                        v-model="targetOpenGroupId"
                        class="rounded border px-2"
                    >
                        <option value="">Add to open group…</option>
                        <option
                            v-for="group in openReimbursementGroups"
                            :key="`target-${group.id}`"
                            :value="group.id"
                        >
                            {{ group.name || `Group #${group.id}` }} (net
                            {{ formatMoney(group.net) }})
                        </option>
                    </select>
                    <button
                        type="button"
                        class="btn rounded border px-3 text-neutral-700 disabled:opacity-50"
                        :disabled="
                            reimbursementActionKey !== null ||
                            !targetOpenGroupId
                        "
                        @click="addSelectedToOpenGroup"
                    >
                        Add to group
                    </button>
                </template>
                <button
                    type="button"
                    class="btn rounded border px-3 text-neutral-700"
                    @click="clearTransactionSelection"
                >
                    Clear
                </button>
            </div>
        </UnmatchedTransactionsList>
        </div>
    </ReconciliationShell>
</template>
