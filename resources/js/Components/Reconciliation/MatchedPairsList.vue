<script setup>
    import { formatMoney } from '../../Composables/useReconciliationFormatting.js';

    defineProps({
        matchedPairs: {
            type: Array,
            required: true,
        },
    });
</script>

<template>
    <section class="space-y-3">
        <p v-if="matchedPairs.length === 0" class="text-sm text-neutral-600">
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
                                pair.transaction.merchant || 'Bank transaction'
                            }}
                        </p>
                        <p class="text-neutral-600">
                            {{
                                pair.transaction.transaction_date ||
                                pair.transaction.posted_at ||
                                'No date'
                            }}
                            · {{ pair.transaction.description }}
                            <span v-if="pair.transaction.card_last_four">
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
                            {{ pair.order.merchant || 'Unknown merchant' }}
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
</template>
