<script setup>
    import ApiTokenList from '../../Components/ApiTokens/ApiTokenList.vue';
    import ApiTokenPlainText from '../../Components/ApiTokens/ApiTokenPlainText.vue';
    import ApiTokensShell from '../../Components/ApiTokens/ApiTokensShell.vue';
    import AuthenticatedLayout from '../../Layouts/AuthenticatedLayout.vue';
    import { useForm } from '@inertiajs/vue3';
    import { computed, ref } from 'vue';

    defineOptions({ layout: AuthenticatedLayout });

    let props = defineProps({
        tokens: {
            type: Array,
            required: true,
        },
        accounts: {
            type: Array,
            required: true,
        },
        merchants: {
            type: Array,
            required: true,
        },
        categories: {
            type: Array,
            required: true,
        },
        endpoint: {
            type: String,
            required: true,
        },
        plainTextToken: {
            type: String,
            default: null,
        },
    });

    let copiedValue = ref('');

    let form = useForm({
        name: 'iPhone Shortcut',
    });

    let samplePayload = computed(() => {
        let account = props.accounts[0];
        let source = 'debit_card';

        if (account?.account_type === 'credit_card') {
            source = 'credit_card';
        }

        return JSON.stringify(
            {
                account_id: account?.id ?? 'ACCOUNT_UUID',
                source,
                spent_at: '2026-08-20 12:00:00',
                amount: 12.5,
                merchant_id: props.merchants[0]?.id ?? null,
                category_id: props.categories[0]?.id ?? null,
                notes: 'Coffee',
            },
            null,
            2,
        );
    });

    let accountTypeLabel = (type) => {
        return type.replaceAll('_', ' ');
    };

    let kindLabel = (kind) => {
        return (
            {
                bill: 'Bill',
                expense: 'Expense',
            }[kind] ?? kind
        );
    };

    let copyText = async (value, key = '') => {
        await navigator.clipboard.writeText(String(value));
        copiedValue.value = key || String(value);
    };

    let submit = () => {
        form.post('/api-tokens/pending-spend');
    };
</script>

<template>
    <ApiTokensShell active-tab="pending-spend">
        <div class="space-y-8">
            <ApiTokenPlainText :token="plainTextToken" />

            <section class="space-y-3">
                <h2 class="text-lg font-semibold">Create pending spend token</h2>
                <form class="flex flex-wrap items-end gap-2" @submit.prevent="submit">
                    <div class="min-w-64 flex-1">
                        <label class="mb-1 block text-sm" for="name">Label</label>
                        <input
                            id="name"
                            v-model="form.name"
                            type="text"
                            class="w-full rounded border px-3"
                            required
                        />
                        <p v-if="form.errors.name" class="mt-1 text-sm text-red-600">
                            {{ form.errors.name }}
                        </p>
                    </div>
                    <button
                        type="submit"
                        class="btn rounded bg-brand px-4 text-sm text-white hover:bg-brand-hover disabled:opacity-50"
                        :disabled="form.processing"
                    >
                        Mint token
                    </button>
                </form>
                <p class="text-sm text-neutral-600">
                    Minting a token with an existing label replaces the old one.
                    Ability:
                    <code>pending-spend:create</code>
                </p>
            </section>

            <ApiTokenList :tokens="tokens" />

            <section class="space-y-3">
                <h2 class="text-lg font-semibold">Shortcut request</h2>
                <p class="text-sm text-neutral-600">
                    <code>GET {{ endpoint }}/options</code>
                    returns expense categories and merchants as
                    <code>name → id</code>. Then
                    <code>POST {{ endpoint }}</code>
                    to create.
                </p>
                <pre
                    class="overflow-x-auto rounded border bg-white px-4 py-3 text-sm"
                >Authorization: Bearer PASTE_TOKEN_HERE
Content-Type: application/json

{{ samplePayload }}</pre>
            </section>

            <section class="space-y-3">
                <h2 class="text-lg font-semibold">Accounts</h2>
                <p v-if="accounts.length === 0" class="text-sm text-neutral-600">
                    No accounts yet.
                </p>
                <ul v-else class="divide-y rounded border">
                    <li
                        v-for="account in accounts"
                        :key="account.id"
                        class="flex flex-wrap items-center justify-between gap-3 px-4 py-3"
                    >
                        <div>
                            <p class="font-medium">{{ account.name }}</p>
                            <p class="text-sm text-neutral-600">
                                {{ accountTypeLabel(account.account_type) }}
                                <template v-if="account.last_four">
                                    · •••• {{ account.last_four }}
                                </template>
                            </p>
                            <code class="break-all text-xs text-neutral-600">{{
                                account.id
                            }}</code>
                        </div>
                        <button
                            type="button"
                            class="btn rounded border px-3 text-sm hover:bg-brand-wash"
                            @click="copyText(account.id, `account-${account.id}`)"
                        >
                            {{
                                copiedValue === `account-${account.id}`
                                    ? 'Copied'
                                    : 'Copy ID'
                            }}
                        </button>
                    </li>
                </ul>
            </section>

            <section class="space-y-3">
                <h2 class="text-lg font-semibold">Merchants</h2>
                <p class="text-sm text-neutral-600">
                    Order-import merchants are omitted; those spends come from
                    orders.
                </p>
                <p v-if="merchants.length === 0" class="text-sm text-neutral-600">
                    No eligible merchants yet.
                </p>
                <ul v-else class="divide-y rounded border">
                    <li
                        v-for="merchant in merchants"
                        :key="merchant.id"
                        class="flex flex-wrap items-center justify-between gap-3 px-4 py-3"
                    >
                        <div>
                            <p class="font-medium">{{ merchant.name }}</p>
                            <code class="text-xs text-neutral-600">{{
                                merchant.id
                            }}</code>
                        </div>
                        <button
                            type="button"
                            class="btn rounded border px-3 text-sm hover:bg-brand-wash"
                            @click="copyText(merchant.id, `merchant-${merchant.id}`)"
                        >
                            {{
                                copiedValue === `merchant-${merchant.id}`
                                    ? 'Copied'
                                    : 'Copy ID'
                            }}
                        </button>
                    </li>
                </ul>
            </section>

            <section class="space-y-3">
                <h2 class="text-lg font-semibold">Categories</h2>
                <p class="text-sm text-neutral-600">
                    Income categories cannot be used for pending spend.
                </p>
                <p v-if="categories.length === 0" class="text-sm text-neutral-600">
                    No eligible categories yet.
                </p>
                <ul v-else class="divide-y rounded border">
                    <li
                        v-for="category in categories"
                        :key="category.id"
                        class="flex flex-wrap items-center justify-between gap-3 px-4 py-3"
                    >
                        <div>
                            <p class="font-medium">{{ category.name }}</p>
                            <p class="text-sm text-neutral-600">
                                {{ kindLabel(category.kind) }}
                                ·
                                <code class="text-xs">{{ category.id }}</code>
                            </p>
                        </div>
                        <button
                            type="button"
                            class="btn rounded border px-3 text-sm hover:bg-brand-wash"
                            @click="
                                copyText(category.id, `category-${category.id}`)
                            "
                        >
                            {{
                                copiedValue === `category-${category.id}`
                                    ? 'Copied'
                                    : 'Copy ID'
                            }}
                        </button>
                    </li>
                </ul>
            </section>
        </div>
    </ApiTokensShell>
</template>
