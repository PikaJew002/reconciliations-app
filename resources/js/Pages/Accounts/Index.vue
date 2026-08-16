<script setup>
    import AuthenticatedLayout from '../../Layouts/AuthenticatedLayout.vue';
    import { Link, router, usePage } from '@inertiajs/vue3';
    import { computed, ref, watch } from 'vue';

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

    let page = usePage();
    let flashSuccess = computed(() => page.props.flash?.success);

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
        <div class="flex flex-wrap items-start justify-between gap-4">
            <div>
                <h1 class="text-2xl font-semibold">Accounts</h1>
                <p class="text-sm text-neutral-600">
                    Bank accounts and their posted-date coverage.
                </p>
            </div>
            <div class="flex flex-wrap gap-2">
                <Link
                    href="/accounts/create"
                    class="rounded bg-neutral-900 px-4 py-2 text-sm text-white"
                >
                    Add account
                </Link>
                <Link
                    href="/venmo/imports"
                    class="rounded border px-4 py-2 text-sm"
                >
                    Import Venmo statement
                </Link>
            </div>
        </div>

        <p
            v-if="flashSuccess"
            class="rounded border border-green-200 bg-green-50 px-3 py-2 text-sm text-green-800"
        >
            {{ flashSuccess }}
        </p>

        <div v-if="bankCoverage" class="rounded border px-4 py-3 text-sm">
            <p class="font-medium">All bank transactions</p>
            <p class="text-neutral-600">
                {{ formatDate(bankCoverage.min) }} →
                {{ formatDate(bankCoverage.max) }}
            </p>
            <p class="mt-1 text-neutral-600">
                Multi-transaction matches skip orders within 3 days of these
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
            <li
                v-for="account in accounts"
                :key="account.id"
                class="flex items-stretch"
            >
                <Link
                    :href="`/accounts/${account.id}`"
                    class="min-w-0 flex-1 px-4 py-3 hover:bg-neutral-50"
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
                <Link
                    :href="`/accounts/${account.id}/imports`"
                    class="shrink-0 self-center px-4 py-3 text-sm text-neutral-700 underline hover:text-neutral-900"
                >
                    Imports
                </Link>
            </li>
        </ul>
    </div>
</template>
