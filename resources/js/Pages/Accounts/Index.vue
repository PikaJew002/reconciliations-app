<script setup>
    import AuthenticatedLayout from '../../Layouts/AuthenticatedLayout.vue';
    import { loadPlaid } from '../../plaid';
    import { Link, router, useForm, usePage } from '@inertiajs/vue3';
    import axios from 'axios';
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
        plaidEnabled: {
            type: Boolean,
            default: false,
        },
        plaidItems: {
            type: Array,
            default: () => [],
        },
    });

    let page = usePage();
    let flashSuccess = computed(() => page.props.flash?.success);
    let flashError = computed(() => page.props.flash?.error);
    let tillerSyncForm = useForm({});
    let plaidSyncForm = useForm({});
    let plaidRemoveForm = useForm({});
    let historyOpen = ref(false);
    let importHistory = ref(false);
    let importFrom = ref('');
    let linkError = ref('');
    let linkOpening = ref(false);
    let plaidHandler = null;

    const localDate = (date) => {
        let month = String(date.getMonth() + 1).padStart(2, '0');
        let day = String(date.getDate()).padStart(2, '0');

        return `${date.getFullYear()}-${month}-${day}`;
    };

    let today = new Date();
    let earliestHistory = new Date(today);
    earliestHistory.setDate(earliestHistory.getDate() - 730);
    const todayIso = localDate(today);
    const earliestIso = localDate(earliestHistory);

    let pageError = computed(() => {
        let errors = page.props.errors ?? {};

        return errors.institution_id || errors.public_token || linkError.value || '';
    });

    let syncTiller = () => {
        tillerSyncForm.post('/accounts/tiller-sync');
    };

    const openHistory = () => {
        historyOpen.value = true;
        importHistory.value = false;
        importFrom.value = '';
        linkError.value = '';
    };

    const plaidErrorMessage = (error) => {
        return error?.response?.data?.message
            || error?.response?.data?.errors?.import_from?.[0]
            || 'Plaid could not start. Try again.';
    };

    const recordExit = (error, metadata) => {
        axios.post('/plaid/link/exit', {
            link_session_id: metadata?.link_session_id ?? null,
            institution_id: metadata?.institution?.institution_id ?? null,
            error_type: error?.error_type ?? null,
            error_code: error?.error_code ?? null,
            request_id: metadata?.request_id ?? null,
        }, {
            withCredentials: true,
            withXSRFToken: true,
        }).catch(() => {});

        if (error) {
            linkError.value = error.display_message || 'The bank connection stopped. You can try again.';
        }
    };

    const openLink = (plaid, token, mode, itemId) => {
        if (plaidHandler) {
            plaidHandler.destroy();
        }

        plaidHandler = plaid.create({
            token,
            onSuccess(publicToken, metadata) {
                if (mode === 'update' && itemId) {
                    router.post(`/plaid/items/${itemId}/repaired`, {
                        link_session_id: metadata?.link_session_id ?? null,
                    });

                    return;
                }

                router.post('/plaid/items', {
                    public_token: publicToken,
                    institution_id: metadata?.institution?.institution_id ?? null,
                    link_session_id: metadata?.link_session_id ?? null,
                });
            },
            onExit: recordExit,
        });

        plaidHandler.open();
    };

    const startLink = async () => {
        linkOpening.value = true;
        linkError.value = '';

        try {
            let plaid = await loadPlaid();
            let response = await axios.post('/plaid/link-token', {
                import_history: importHistory.value,
                import_from: importHistory.value ? importFrom.value : null,
            }, {
                withCredentials: true,
                withXSRFToken: true,
            });

            historyOpen.value = false;
            openLink(plaid, response.data.link_token, response.data.mode, null);
        } catch (error) {
            linkError.value = plaidErrorMessage(error);
        } finally {
            linkOpening.value = false;
        }
    };

    const reconnect = async (itemId) => {
        linkError.value = '';

        try {
            let plaid = await loadPlaid();
            let response = await axios.post(`/plaid/items/${itemId}/link-token`, {}, {
                withCredentials: true,
                withXSRFToken: true,
            });

            openLink(plaid, response.data.link_token, response.data.mode, response.data.item_id);
        } catch (error) {
            linkError.value = plaidErrorMessage(error);
        }
    };

    const syncPlaid = (itemId) => {
        plaidSyncForm.post(`/plaid/items/${itemId}/sync`);
    };

    const removePlaid = (itemId) => {
        plaidRemoveForm.delete(`/plaid/items/${itemId}`);
    };

    const plaidStatus = (item) => {
        if (item.error_code === 'ITEM_LOGIN_REQUIRED') {
            return 'This bank needs to be reconnected.';
        }

        if (item.error_code === 'PENDING_DISCONNECT' || item.error_code === 'PENDING_EXPIRATION') {
            return 'This bank connection will expire soon. Reconnect it to keep importing.';
        }

        if (item.error_code === 'USER_PERMISSION_REVOKED') {
            return 'Access to this bank was revoked. Remove it and add the bank again.';
        }

        if (item.error_code) {
            return `Plaid reported ${item.error_code}.`;
        }

        if (item.importing_history) {
            return 'Still importing history.';
        }

        return '';
    };

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
                <button
                    v-if="plaidEnabled"
                    type="button"
                    class="btn rounded bg-brand px-4 text-sm text-white hover:bg-brand-hover"
                    @click="openHistory"
                >
                    Add a bank account
                </button>
                <Link
                    href="/accounts/create"
                    class="btn rounded px-4 text-sm"
                    :class="plaidEnabled ? 'border' : 'bg-brand text-white hover:bg-brand-hover'"
                >
                    Add account
                </Link>
                <Link
                    href="/venmo/imports"
                    class="btn rounded border px-4 text-sm"
                >
                    Import Venmo statement
                </Link>
                <button
                    type="button"
                    class="btn rounded border px-4 text-sm disabled:opacity-50"
                    :disabled="tillerSyncForm.processing"
                    @click="syncTiller"
                >
                    Sync Tiller sheet
                </button>
            </div>
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
            v-if="pageError"
            class="rounded border border-red-200 bg-red-50 px-3 py-2 text-sm text-red-800"
        >
            {{ pageError }}
        </p>

        <section v-if="plaidEnabled && plaidItems.length > 0" class="space-y-3">
            <h2 class="text-lg font-semibold">Connected banks</h2>
            <ul class="divide-y rounded border">
                <li
                    v-for="item in plaidItems"
                    :key="item.id"
                    class="flex flex-wrap items-center justify-between gap-3 px-4 py-3"
                >
                    <div>
                        <p class="font-medium">{{ item.institution_name }}</p>
                        <p class="text-sm text-neutral-600">
                            <template v-for="(account, index) in item.accounts" :key="account.name + index">
                                <span v-if="index > 0"> · </span>
                                {{ account.name }}
                                <template v-if="account.mask"> •••• {{ account.mask }}</template>
                                <template v-if="!account.mapped"> (not imported)</template>
                            </template>
                        </p>
                        <p v-if="plaidStatus(item)" class="text-sm text-amber-800">
                            {{ plaidStatus(item) }}
                        </p>
                    </div>
                    <div class="flex flex-wrap gap-2">
                        <button
                            v-if="item.needs_reconnect"
                            type="button"
                            class="btn rounded border px-3 text-sm"
                            @click="reconnect(item.id)"
                        >
                            Reconnect
                        </button>
                        <button
                            type="button"
                            class="btn rounded border px-3 text-sm disabled:opacity-50"
                            :disabled="plaidSyncForm.processing"
                            @click="syncPlaid(item.id)"
                        >
                            Sync
                        </button>
                        <button
                            type="button"
                            class="btn rounded border px-3 text-sm disabled:opacity-50"
                            :disabled="plaidRemoveForm.processing"
                            @click="removePlaid(item.id)"
                        >
                            Remove
                        </button>
                    </div>
                </li>
            </ul>
        </section>

        <div
            v-if="historyOpen"
            class="rounded border p-4"
        >
            <h2 class="text-lg font-semibold">Import existing transactions?</h2>
            <p class="mt-1 text-sm text-neutral-600">
                No is the default. Plaid can go back at most 730 days, and this
                choice is locked to this bank unless you remove it and add it
                again. Your credentials stay at the bank.
            </p>
            <div class="mt-3 space-y-2 text-sm">
                <label class="flex items-center gap-2">
                    <input v-model="importHistory" type="radio" :value="false" />
                    No, only transactions from now on
                </label>
                <label class="flex items-center gap-2">
                    <input v-model="importHistory" type="radio" :value="true" />
                    Yes, import from a start date
                </label>
                <input
                    v-if="importHistory"
                    v-model="importFrom"
                    type="date"
                    :min="earliestIso"
                    :max="todayIso"
                    required
                    class="rounded border px-3 py-2"
                />
            </div>
            <div class="mt-4 flex gap-2">
                <button
                    type="button"
                    class="btn rounded bg-brand px-4 text-sm text-white hover:bg-brand-hover disabled:opacity-50"
                    :disabled="linkOpening || (importHistory && !importFrom)"
                    @click="startLink"
                >
                    Continue
                </button>
                <button
                    type="button"
                    class="btn rounded border px-4 text-sm"
                    @click="historyOpen = false"
                >
                    Cancel
                </button>
            </div>
        </div>

        <div v-if="bankCoverage" class="rounded border px-4 py-3 text-sm">
            <p class="font-medium">All bank transactions</p>
            <p class="text-neutral-600">
                {{ formatDate(bankCoverage.min) }} →
                {{ formatDate(bankCoverage.max) }}
            </p>
            <p class="mt-1 text-neutral-600">
                Multi-transaction matches skip orders more than 10 days before
                this range starts, or after it ends.
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
                class="min-w-64 flex-1 rounded border px-3 text-sm"
            />
            <button type="submit" class="btn rounded border px-4 text-sm">
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
                            <p>{{ account.transaction_count }} transactions</p>
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
                    v-if="!account.is_off_book"
                    :href="`/accounts/${account.id}/imports`"
                    class="shrink-0 self-center px-4 py-3 text-sm text-neutral-700 underline hover:text-neutral-900"
                >
                    Imports
                </Link>
            </li>
        </ul>
    </div>
</template>
