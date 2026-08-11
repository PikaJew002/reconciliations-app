<script setup>
    import { formatMoney } from '../../Composables/useReconciliationFormatting.js';

    defineProps({
        unmatchedOrders: {
            type: Array,
            required: true,
        },
    });
</script>

<template>
    <section class="space-y-3">
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
</template>
