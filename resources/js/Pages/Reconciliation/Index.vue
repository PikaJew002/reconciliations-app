<script setup>
import { Link, usePage } from '@inertiajs/vue3';
import { computed } from 'vue';

defineProps({
    summary: {
        type: Object,
        required: true,
    },
    unmatchedOrders: {
        type: Array,
        required: true,
    },
    matchedPairs: {
        type: Array,
        required: true,
    },
});

let page = usePage();
let user = computed(() => page.props.auth.user);

function formatMoney(amount) {
    let value = Number(amount ?? 0);
    return value.toLocaleString('en-US', {
        style: 'currency',
        currency: 'USD',
    });
}
</script>

<template>
    <div class="mx-auto max-w-3xl space-y-8 p-8">
        <div class="flex items-center justify-between gap-4">
            <div>
                <Link href="/imports" class="text-sm underline">Back to imports</Link>
                <h1 class="mt-2 text-2xl font-semibold">Reconciliation</h1>
                <p class="text-sm text-neutral-600">Signed in as {{ user?.email }}</p>
            </div>
        </div>

        <dl class="grid grid-cols-2 gap-3 text-sm sm:grid-cols-3">
            <div class="rounded border px-3 py-2">
                <dt class="text-neutral-600">Unmatched orders</dt>
                <dd class="text-lg font-medium">{{ summary.unmatched_orders }}</dd>
            </div>
            <div class="rounded border px-3 py-2">
                <dt class="text-neutral-600">Reconciled orders</dt>
                <dd class="text-lg font-medium">{{ summary.reconciled_orders }}</dd>
            </div>
            <div class="rounded border px-3 py-2">
                <dt class="text-neutral-600">Matched pairs</dt>
                <dd class="text-lg font-medium">{{ summary.matched_pairs }}</dd>
            </div>
            <div class="rounded border px-3 py-2">
                <dt class="text-neutral-600">Unmatched transactions</dt>
                <dd class="text-lg font-medium">{{ summary.unmatched_transactions }}</dd>
            </div>
            <div class="rounded border px-3 py-2">
                <dt class="text-neutral-600">Partial transactions</dt>
                <dd class="text-lg font-medium">{{ summary.partial_transactions }}</dd>
            </div>
        </dl>

        <section class="space-y-3">
            <h2 class="text-lg font-semibold">Unmatched orders</h2>

            <p v-if="unmatchedOrders.length === 0" class="text-sm text-neutral-600">
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
                            <span class="font-normal text-neutral-600">#{{ order.order_number }}</span>
                        </p>
                        <p class="text-neutral-600">
                            {{ order.ordered_at || 'No date' }}
                            <span v-if="order.payment_last_four"> · card {{ order.payment_last_four }}</span>
                        </p>
                    </div>
                    <div class="text-right">
                        <p class="font-medium">{{ formatMoney(order.total) }}</p>
                        <p class="text-neutral-600">{{ order.status }}</p>
                    </div>
                </li>
            </ul>
        </section>

        <section class="space-y-3">
            <h2 class="text-lg font-semibold">Matched pairs</h2>

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
                                {{ pair.transaction.merchant || 'Bank transaction' }}
                            </p>
                            <p class="text-neutral-600">
                                {{ pair.transaction.transaction_date || pair.transaction.posted_at || 'No date' }}
                                · {{ pair.transaction.description }}
                                <span v-if="pair.transaction.card_last_four">
                                    · card {{ pair.transaction.card_last_four }}
                                </span>
                            </p>
                        </div>
                        <div class="text-right">
                            <p class="font-medium">{{ formatMoney(pair.transaction.amount) }}</p>
                            <p class="text-neutral-600">{{ pair.transaction.status }}</p>
                        </div>
                    </div>

                    <div class="flex items-start justify-between gap-4 border-l-2 border-neutral-200 pl-3">
                        <div>
                            <p class="font-medium">
                                {{ pair.order.merchant || 'Unknown merchant' }}
                                <span class="font-normal text-neutral-600">#{{ pair.order.order_number }}</span>
                            </p>
                            <p class="text-neutral-600">
                                {{ pair.order.ordered_at || 'No date' }}
                                <span v-if="pair.order.payment_last_four">
                                    · card {{ pair.order.payment_last_four }}
                                </span>
                            </p>
                        </div>
                        <div class="text-right">
                            <p class="font-medium">{{ formatMoney(pair.order.total) }}</p>
                            <p class="text-neutral-600">{{ pair.order.status }}</p>
                        </div>
                    </div>

                    <p class="text-neutral-600">
                        Allocated {{ formatMoney(pair.allocated_amount) }}
                    </p>
                </li>
            </ul>
        </section>
    </div>
</template>
