<script setup>
    import AuthenticatedLayout from '../../Layouts/AuthenticatedLayout.vue';
    import { Link, router } from '@inertiajs/vue3';
    import { computed, ref, watch } from 'vue';

    defineOptions({ layout: AuthenticatedLayout });

    let props = defineProps({
        account: {
            type: Object,
            required: true,
        },
        transactions: {
            type: Array,
            required: true,
        },
        transactionsTruncated: {
            type: Boolean,
            required: true,
        },
        filters: {
            type: Object,
            required: true,
        },
    });

    let search = ref(props.filters.q ?? '');

    watch(
        () => props.filters.q,
        (value) => {
            search.value = value ?? '';
        },
    );

    let nearRecentEdge = computed(() => {
        if (!props.account.max_posted_at) {
            return false;
        }

        let max = new Date(`${props.account.max_posted_at}T00:00:00`);
        let today = new Date();
        today.setHours(0, 0, 0, 0);
        let diffDays = Math.abs((today - max) / (1000 * 60 * 60 * 24));

        return diffDays <= 7;
    });

    let submitSearch = () => {
        router.get(
            `/accounts/${props.account.id}`,
            { q: search.value || undefined },
            {
                preserveState: true,
                replace: true,
            },
        );
    };

    let formatDate = (value) => value || '—';

    let formatMoney = (amount) => {
        return new Intl.NumberFormat('en-US', {
            style: 'currency',
            currency: 'USD',
        }).format(amount);
    };

    let statusStyles = {
        matched: {
            row: 'border-l-green-500',
            badge: 'bg-green-50 text-green-800',
            label: 'Matched',
        },
        unmatched: {
            row: 'border-l-amber-500',
            badge: 'bg-amber-50 text-amber-900',
            label: 'Unmatched',
        },
        partial: {
            row: 'border-l-sky-500',
            badge: 'bg-sky-50 text-sky-900',
            label: 'Partial',
        },
        ignored: {
            row: 'border-l-neutral-400',
            badge: 'bg-neutral-100 text-neutral-700',
            label: 'Ignored',
        },
    };

    let statusStyle = (status) =>
        statusStyles[status] ?? {
            row: 'border-l-neutral-300',
            badge: 'bg-neutral-100 text-neutral-700',
            label: status,
        };
</script>

<template>
    <div class="space-y-6">
        <div>
            <Link href="/accounts" class="text-sm underline"
                >Back to accounts</Link
            >
            <h1 class="mt-2 text-2xl font-semibold">{{ account.name }}</h1>
            <p class="text-sm text-neutral-600">
                {{ account.institution_name }}
                · {{ account.account_type }}
                <template v-if="account.last_four">
                    · •••• {{ account.last_four }}</template
                >
            </p>
        </div>

        <div class="rounded border px-4 py-3 text-sm">
            <p class="font-medium">Posted-date coverage</p>
            <p class="text-neutral-600">
                {{ formatDate(account.min_posted_at) }} →
                {{ formatDate(account.max_posted_at) }}
                <template v-if="account.coverage_span_days !== null">
                    ({{ account.coverage_span_days }} day span)
                </template>
            </p>
            <p class="mt-1 text-neutral-600">
                {{ account.transaction_count }} transactions
            </p>
            <p v-if="nearRecentEdge" class="mt-2 text-amber-800">
                Coverage ends near today. Import newer bank history if recent
                multi-transaction matches are being skipped.
            </p>
            <p class="mt-2">
                <Link
                    href="/imports/bank-transactions/create"
                    class="underline"
                >
                    Import more bank transactions
                </Link>
            </p>
        </div>

        <form class="flex flex-wrap gap-2" @submit.prevent="submitSearch">
            <input
                v-model="search"
                type="search"
                placeholder="Search description or amount"
                class="min-w-64 flex-1 rounded border px-3 py-2 text-sm"
            />
            <button type="submit" class="rounded border px-4 py-2 text-sm">
                Search
            </button>
        </form>

        <div v-if="transactions.length === 0" class="text-sm text-neutral-600">
            No transactions match this search.
        </div>

        <ul v-else class="divide-y rounded border text-sm">
            <li
                v-for="transaction in transactions"
                :key="transaction.id"
                class="flex items-start justify-between gap-4 border-l-4 px-4 py-3"
                :class="statusStyle(transaction.status).row"
            >
                <div class="min-w-0">
                    <div class="flex flex-wrap items-center gap-2">
                        <p class="font-medium">{{ transaction.description }}</p>
                        <span
                            class="inline-flex items-center gap-1 rounded px-1.5 py-0.5 text-xs font-medium"
                            :class="statusStyle(transaction.status).badge"
                        >
                            <span
                                class="size-1.5 rounded-full"
                                :class="{
                                    'bg-green-600':
                                        transaction.status === 'matched',
                                    'bg-amber-500':
                                        transaction.status === 'unmatched',
                                    'bg-sky-500':
                                        transaction.status === 'partial',
                                    'bg-neutral-500':
                                        transaction.status === 'ignored' ||
                                        !['matched', 'unmatched', 'partial'].includes(
                                            transaction.status,
                                        ),
                                }"
                            />
                            {{ statusStyle(transaction.status).label }}
                        </span>
                    </div>
                    <p class="text-neutral-600">
                        {{ formatDate(transaction.posted_at) }}
                        <template v-if="transaction.merchant">
                            · {{ transaction.merchant.name }}
                        </template>
                        <template v-if="transaction.card_last_four">
                            · •••• {{ transaction.card_last_four }}
                        </template>
                    </p>
                </div>
                <p class="shrink-0 font-medium">
                    {{ formatMoney(transaction.amount) }}
                </p>
            </li>
        </ul>

        <p v-if="transactionsTruncated" class="text-sm text-neutral-600">
            Showing the newest 50 matching transactions.
        </p>
    </div>
</template>
