<script setup>
    import AuthenticatedLayout from '../../Layouts/AuthenticatedLayout.vue';
    import { Link, router } from '@inertiajs/vue3';
    import { ref, watch } from 'vue';

    defineOptions({ layout: AuthenticatedLayout });

    let props = defineProps({
        accounts: {
            type: Array,
            required: true,
        },
        bankCoverage: {
            type: Object,
            default: null,
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

    let submitSearch = () => {
        router.get(
            '/accounts',
            { q: search.value || undefined },
            {
                preserveState: true,
                replace: true,
            },
        );
    };

    let formatDate = (value) => {
        if (!value) {
            return '—';
        }

        return value;
    };
</script>

<template>
    <div class="space-y-6">
        <div>
            <h1 class="text-2xl font-semibold">Accounts</h1>
            <p class="text-sm text-neutral-600">
                Accounts with imported bank activity and their posted-date
                coverage.
            </p>
        </div>

        <div v-if="bankCoverage" class="rounded border px-4 py-3 text-sm">
            <p class="font-medium">All bank transactions</p>
            <p class="text-neutral-600">
                {{ formatDate(bankCoverage.min) }} →
                {{ formatDate(bankCoverage.max) }}
            </p>
            <p class="mt-1 text-neutral-600">
                Multi-transaction matches skip orders within 7 days of these
                edges.
            </p>
        </div>
        <p v-else class="text-sm text-neutral-600">
            No bank transactions imported yet.
        </p>

        <form class="flex flex-wrap gap-2" @submit.prevent="submitSearch">
            <input
                v-model="search"
                type="search"
                placeholder="Search name, institution, or last four"
                class="min-w-64 flex-1 rounded border px-3 py-2 text-sm"
            />
            <button type="submit" class="rounded border px-4 py-2 text-sm">
                Search
            </button>
        </form>

        <div v-if="accounts.length === 0" class="text-sm text-neutral-600">
            No accounts match this search.
        </div>

        <ul v-else class="divide-y rounded border">
            <li v-for="account in accounts" :key="account.id">
                <Link
                    :href="`/accounts/${account.id}`"
                    class="block px-4 py-3 hover:bg-neutral-50"
                >
                    <div class="flex items-start justify-between gap-4">
                        <div>
                            <p class="font-medium">{{ account.name }}</p>
                            <p class="text-sm text-neutral-600">
                                {{ account.institution_name }}
                                · {{ account.account_type }}
                                <template v-if="account.last_four">
                                    · •••• {{ account.last_four }}</template
                                >
                            </p>
                        </div>
                        <div class="text-right text-sm">
                            <p>{{ account.transaction_count }} txs</p>
                            <p class="text-neutral-600">
                                {{ formatDate(account.min_posted_at) }} →
                                {{ formatDate(account.max_posted_at) }}
                            </p>
                            <p
                                v-if="account.coverage_span_days !== null"
                                class="text-neutral-600"
                            >
                                {{ account.coverage_span_days }} day span
                            </p>
                        </div>
                    </div>
                </Link>
            </li>
        </ul>
    </div>
</template>
