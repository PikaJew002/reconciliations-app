<script setup>
    import AuthenticatedLayout from '../../Layouts/AuthenticatedLayout.vue';
    import { Link, useForm } from '@inertiajs/vue3';

    defineOptions({ layout: AuthenticatedLayout });

    let props = defineProps({
        item: {
            type: Object,
            required: true,
        },
        plaidAccounts: {
            type: Array,
            required: true,
        },
        spendableAccounts: {
            type: Array,
            required: true,
        },
    });

    let form = useForm({
        mappings: props.plaidAccounts.map((account) => ({
            id: account.id,
            account_id: account.account_id ?? account.suggested_account_id ?? '',
        })),
    });

    const save = () => {
        form.transform((data) => ({
            mappings: data.mappings.map((mapping) => ({
                id: mapping.id,
                account_id: mapping.account_id || null,
            })),
        })).put(`/plaid/items/${props.item.id}/accounts`);
    };

    const labelFor = (account) => {
        let mask = account.last_four ? ` · •••• ${account.last_four}` : '';

        return `${account.name} · ${account.institution_name}${mask}`;
    };
</script>

<template>
    <div class="space-y-6">
        <div>
            <h1 class="text-2xl font-semibold">
                Map {{ item.institution_name || 'bank' }} accounts
            </h1>
            <p class="text-sm text-neutral-600">
                Choose an existing Spendable account for each bank account you
                want to import. Unmapped accounts are skipped.
            </p>
        </div>

        <p class="rounded border border-amber-200 bg-amber-50 px-3 py-2 text-sm text-amber-900">
            Transactions before {{ item.import_from }} are not imported.
            <template v-if="item.cursor_started">
                This bank has already been synced, so accounts you map now only
                receive transactions that arrive from here on.
            </template>
            <template v-else>
                Accounts you leave unmapped will not get their history later,
                because the first sync moves the import forward.
            </template>
            Going back further than {{ item.import_from }} means removing this
            bank and adding it again.
        </p>

        <form class="space-y-4" @submit.prevent="save">
            <div
                v-for="(account, index) in plaidAccounts"
                :key="account.id"
                class="rounded border p-4"
            >
                <p class="font-medium">{{ account.name }}</p>
                <p class="text-sm text-neutral-600">
                    {{ account.subtype || account.type }}
                    <template v-if="account.mask"> · •••• {{ account.mask }}</template>
                </p>
                <label class="mt-3 block text-sm">
                    <span class="text-neutral-600">Spendable account</span>
                    <select
                        v-model="form.mappings[index].account_id"
                        class="mt-1 w-full rounded border px-3 py-2 text-sm"
                    >
                        <option value="">Don't import</option>
                        <option
                            v-for="spendable in spendableAccounts"
                            :key="spendable.id"
                            :value="spendable.id"
                        >
                            {{ labelFor(spendable) }}
                        </option>
                    </select>
                </label>
                <p
                    v-if="account.suggested_account_id && !account.account_id"
                    class="mt-1 text-xs text-neutral-500"
                >
                    Suggested from the last four digits.
                </p>
            </div>

            <div class="flex flex-wrap gap-2">
                <button
                    type="submit"
                    class="btn rounded bg-brand px-4 text-sm text-white hover:bg-brand-hover disabled:opacity-50"
                    :disabled="form.processing"
                >
                    Save mapping
                </button>
                <Link href="/accounts" class="btn rounded border px-4 text-sm">
                    Cancel
                </Link>
            </div>
        </form>
    </div>
</template>
