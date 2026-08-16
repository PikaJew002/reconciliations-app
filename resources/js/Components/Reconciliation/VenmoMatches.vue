<script setup>
    import {
        accountLabel,
        formatMoney,
    } from '../../Composables/useReconciliationFormatting.js';
    import { router } from '@inertiajs/vue3';
    import { reactive, ref } from 'vue';

    defineProps({
        suggestedVenmoMatches: {
            type: Array,
            default: () => [],
        },
        unmatchedVenmoActivities: {
            type: Array,
            default: () => [],
        },
    });

    let actionKey = ref(null);
    let selectedCandidateIds = reactive({});

    function formatOccurredAt(value) {
        if (!value) {
            return 'No date';
        }

        return String(value).slice(0, 10);
    }

    function candidateId(activity) {
        if (selectedCandidateIds[activity.id]) {
            return selectedCandidateIds[activity.id];
        }

        return activity.suggested_transaction?.id
            ? String(activity.suggested_transaction.id)
            : activity.candidates[0]
              ? String(activity.candidates[0].id)
              : '';
    }

    function confirmMatch(activity) {
        actionKey.value = `confirm-${activity.id}`;
        router.post(
            `/reconciliation/venmo/${activity.id}/confirm`,
            {},
            {
                preserveScroll: true,
                onFinish: () => {
                    actionKey.value = null;
                },
            },
        );
    }

    function rejectMatch(activity) {
        actionKey.value = `reject-${activity.id}`;
        router.post(
            `/reconciliation/venmo/${activity.id}/reject`,
            {},
            {
                preserveScroll: true,
                onFinish: () => {
                    actionKey.value = null;
                },
            },
        );
    }

    function assignMatch(activity) {
        let bankTransactionId = Number(candidateId(activity));

        if (!bankTransactionId) {
            return;
        }

        actionKey.value = `assign-${activity.id}`;
        router.post(
            `/reconciliation/venmo/${activity.id}/assign`,
            { bank_transaction_id: bankTransactionId },
            {
                preserveScroll: true,
                onFinish: () => {
                    actionKey.value = null;
                },
            },
        );
    }
</script>

<template>
    <div class="space-y-8">
        <section v-if="suggestedVenmoMatches.length > 0" class="space-y-4">
            <div>
                <h2 class="text-base font-semibold">Suggested Venmo matches</h2>
                <p class="text-sm text-neutral-600">
                    Statement activity that likely explains a Venmo bank line.
                    Confirm to keep the label on that transaction.
                </p>
            </div>

            <ul class="space-y-3">
                <li
                    v-for="activity in suggestedVenmoMatches"
                    :key="`suggested-venmo-${activity.id}`"
                    class="space-y-3 rounded border px-4 py-3 text-sm"
                >
                    <div class="flex flex-wrap items-start justify-between gap-4">
                        <div class="space-y-1">
                            <p class="font-medium">{{ activity.label }}</p>
                            <p class="text-neutral-600">
                                {{ formatOccurredAt(activity.occurred_at) }}
                                · {{ activity.type.replaceAll('_', ' ') }}
                            </p>
                            <p
                                v-if="activity.suggested_transaction"
                                class="text-neutral-600"
                            >
                                Bank:
                                {{
                                    accountLabel(activity.suggested_transaction)
                                }}
                                ·
                                {{
                                    activity.suggested_transaction.posted_at ||
                                    'No date'
                                }}
                                ·
                                {{
                                    activity.suggested_transaction.description
                                }}
                            </p>
                        </div>
                        <p class="font-medium">
                            {{ formatMoney(activity.amount) }}
                        </p>
                    </div>

                    <div class="flex flex-wrap gap-2">
                        <button
                            type="button"
                            class="btn rounded bg-brand hover:bg-brand-hover px-3 text-white disabled:opacity-50"
                            :disabled="actionKey !== null"
                            @click="confirmMatch(activity)"
                        >
                            {{
                                actionKey === `confirm-${activity.id}`
                                    ? 'Confirming…'
                                    : 'Confirm match'
                            }}
                        </button>
                        <button
                            type="button"
                            class="btn rounded border px-3 text-neutral-700 disabled:opacity-50"
                            :disabled="actionKey !== null"
                            @click="rejectMatch(activity)"
                        >
                            {{
                                actionKey === `reject-${activity.id}`
                                    ? 'Dismissing…'
                                    : 'Dismiss'
                            }}
                        </button>
                    </div>
                </li>
            </ul>
        </section>

        <section v-if="unmatchedVenmoActivities.length > 0" class="space-y-4">
            <div>
                <h2 class="text-base font-semibold">Unmatched Venmo activity</h2>
                <p class="text-sm text-neutral-600">
                    Card charges and bank transfers from the statement that do
                    not yet have a bank transaction. Link one if it already
                    posted.
                </p>
            </div>

            <ul class="space-y-3">
                <li
                    v-for="activity in unmatchedVenmoActivities"
                    :key="`unmatched-venmo-${activity.id}`"
                    class="space-y-3 rounded border px-4 py-3 text-sm"
                >
                    <div class="flex flex-wrap items-start justify-between gap-4">
                        <div class="space-y-1">
                            <p class="font-medium">{{ activity.label }}</p>
                            <p class="text-neutral-600">
                                {{ formatOccurredAt(activity.occurred_at) }}
                                · {{ activity.type.replaceAll('_', ' ') }}
                                <template v-if="activity.funding_last_four">
                                    · card
                                    {{ activity.funding_last_four }}
                                </template>
                                <template v-else-if="activity.destination_last_four">
                                    · bank
                                    {{ activity.destination_last_four }}
                                </template>
                            </p>
                        </div>
                        <p class="font-medium">
                            {{ formatMoney(activity.amount) }}
                        </p>
                    </div>

                    <form
                        v-if="activity.candidates.length > 0"
                        class="flex flex-wrap items-end gap-2"
                        @submit.prevent="assignMatch(activity)"
                    >
                        <label class="min-w-64 flex-1 text-sm">
                            <span class="mb-1 block text-neutral-600"
                                >Bank transaction</span
                            >
                            <select
                                class="w-full rounded border px-3"
                                :value="candidateId(activity)"
                                @change="
                                    selectedCandidateIds[activity.id] =
                                        $event.target.value
                                "
                            >
                                <option
                                    v-for="candidate in activity.candidates"
                                    :key="candidate.id"
                                    :value="String(candidate.id)"
                                >
                                    {{ candidate.posted_at || 'No date' }}
                                    · {{ accountLabel(candidate) }}
                                    · {{ candidate.description }}
                                    · {{ formatMoney(candidate.amount) }}
                                </option>
                            </select>
                        </label>
                        <button
                            type="submit"
                            class="btn rounded bg-brand hover:bg-brand-hover px-3 text-white disabled:opacity-50"
                            :disabled="actionKey !== null"
                        >
                            {{
                                actionKey === `assign-${activity.id}`
                                    ? 'Linking…'
                                    : 'Link transaction'
                            }}
                        </button>
                    </form>
                    <p v-else class="text-neutral-600">
                        No matching Venmo bank lines in the date window.
                    </p>
                </li>
            </ul>
        </section>
    </div>
</template>
