<script setup>
    import {
        accountLabel,
        formatMoney,
    } from '../../Composables/useReconciliationFormatting.js';
    import { router } from '@inertiajs/vue3';
    import { ref } from 'vue';

    defineProps({
        suggestedTransfers: {
            type: Array,
            default: () => [],
        },
    });

    let transferActionId = ref(null);

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
</script>

<template>
    <div class="space-y-4">
        <div>
            <h2 class="text-base font-semibold">Suggested transfers</h2>
            <p class="text-sm text-neutral-600">
                Internal account transfers. Confirm to hide both sides from
                expense tracking.
            </p>
        </div>

        <ul class="space-y-3">
            <li
                v-for="link in suggestedTransfers"
                :key="`transfer-${link.id}`"
                class="space-y-3 rounded border px-4 py-3 text-sm"
            >
                <div class="flex flex-wrap items-start justify-between gap-4">
                    <div class="space-y-2">
                        <div>
                            <p class="font-medium">
                                From {{ accountLabel(link.debit) }}
                            </p>
                            <p class="text-neutral-600">
                                {{ link.debit.posted_at || 'No date' }} ·
                                {{ link.debit.description }}
                            </p>
                        </div>
                        <div>
                            <p class="font-medium">
                                To {{ accountLabel(link.credit) }}
                            </p>
                            <p class="text-neutral-600">
                                {{ link.credit.posted_at || 'No date' }} ·
                                {{ link.credit.description }}
                            </p>
                        </div>
                    </div>
                    <div class="text-right">
                        <p class="font-medium">
                            {{ formatMoney(Math.abs(link.debit.amount)) }}
                        </p>
                        <p
                            v-if="link.match_confidence != null"
                            class="text-neutral-600"
                        >
                            {{ Math.round(link.match_confidence) }}% confidence
                        </p>
                    </div>
                </div>

                <div class="flex flex-wrap gap-2">
                    <button
                        type="button"
                        class="btn rounded bg-brand hover:bg-brand-hover px-3 text-white disabled:opacity-50"
                        :disabled="transferActionId !== null"
                        @click="confirmTransfer(link)"
                    >
                        {{
                            transferActionId === `confirm-${link.id}`
                                ? 'Confirming…'
                                : 'Confirm transfer'
                        }}
                    </button>
                    <button
                        type="button"
                        class="btn rounded border px-3 text-neutral-700 disabled:opacity-50"
                        :disabled="transferActionId !== null"
                        @click="rejectTransfer(link)"
                    >
                        {{
                            transferActionId === `reject-${link.id}`
                                ? 'Dismissing…'
                                : 'Dismiss'
                        }}
                    </button>
                </div>
            </li>
        </ul>
    </div>
</template>
