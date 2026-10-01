<script setup>
    import ApiTokenList from '../../Components/ApiTokens/ApiTokenList.vue';
    import ApiTokenPlainText from '../../Components/ApiTokens/ApiTokenPlainText.vue';
    import ApiTokensShell from '../../Components/ApiTokens/ApiTokensShell.vue';
    import AuthenticatedLayout from '../../Layouts/AuthenticatedLayout.vue';
    import { useForm } from '@inertiajs/vue3';

    defineOptions({ layout: AuthenticatedLayout });

    defineProps({
        tokens: {
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

    let form = useForm({
        name: 'Google Sheets',
    });

    let samplePayload = `{
  "transactions": [
    {
      "date": "9/28/2026",
      "description": "KY Farm Bureau Kyfarm Bur",
      "category": "Insurance",
      "amount": "-$278.04",
      "account": "Joint Account 1",
      "account_number": "1758",
      "institution": "Cumberland Valley National Bank & Trust Company",
      "month": "9/1/26",
      "week": "9/27/26",
      "transaction_id": "DZDajja0pjTbvKVbnDmOT80aoXmKpvIXRy6ZZ",
      "account_id": "6ab9a5af34a0b8d3bc2b870a",
      "import_tag": null,
      "check_number": null,
      "full_description": "KY FARM BUREAU KYFARM BUR",
      "date_added": "9/29/26",
      "category_hint": "GENERAL_SERVICES: GENERAL_SERVICES_INSURANCE",
      "categorized_by": "Description Match",
      "categorized_date": "9/29/26",
      "source": "Plaid"
    }
  ]
}`;

    let submit = () => {
        form.post('/api-tokens/transaction-import');
    };
</script>

<template>
    <ApiTokensShell active-tab="transaction-import">
        <div class="space-y-8">
            <ApiTokenPlainText :token="plainTextToken" />

            <section class="space-y-3">
                <h2 class="text-lg font-semibold">Create transaction import token</h2>
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
                    Paste this token into the Apps Script that sends transactions
                    to import. Minting a token with an existing label replaces
                    the old one. Ability:
                    <code>transactions:import</code>
                </p>
            </section>

            <ApiTokenList :tokens="tokens" />

            <section class="space-y-3">
                <h2 class="text-lg font-semibold">Import request</h2>
                <p class="text-sm text-neutral-600">
                    <code>POST {{ endpoint }}</code>
                    accepts up to 2,000 sheet rows. Blank category, import tag,
                    check number, categorized by, and categorized date are
                    allowed. Amounts can be currency text like
                    <code>-$278.04</code>
                    or a number.
                </p>
                <pre
                    class="overflow-x-auto rounded border bg-white px-4 py-3 text-sm"
                >Authorization: Bearer PASTE_TOKEN_HERE
Content-Type: application/json

{{ samplePayload }}</pre>
            </section>
        </div>
    </ApiTokensShell>
</template>
