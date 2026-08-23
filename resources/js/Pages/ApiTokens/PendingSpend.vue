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
        name: 'iPhone Shortcut',
    });

    let samplePayload = `{
  "account_id": "11111111-1111-1111-1111-111111111111",
  "spent_at": "2026-08-20 12:00:00",
  "amount": 12.5,
  "merchant_id": 3,
  "category_id": 1,
  "notes": "Coffee"
}`;

    let sampleOptionsResponse = `{
  "categories": {
    "Dining": 1,
    "Groceries": 2
  },
  "merchants": {
    "Buc-ee's": 3,
    "Zebra Cafe": 4
  },
  "accounts": {
    "CVNB Checking": "11111111-1111-1111-1111-111111111111",
    "Capital One": "22222222-2222-2222-2222-222222222222"
  }
}`;

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
                    returns expense categories, merchants, and
                    checking/credit card accounts as
                    <code>name → id</code>. Then
                    <code>POST {{ endpoint }}</code>
                    to create. Card vs credit is derived from the
                    account. For Venmo, add
                    <code>"venmo": true</code>
                    and you can omit
                    <code>merchant_id</code>.
                </p>
                <pre
                    class="overflow-x-auto rounded border bg-white px-4 py-3 text-sm"
                >POST {{ endpoint }}

Authorization: Bearer PASTE_TOKEN_HERE
Content-Type: application/json

{{ samplePayload }}</pre>
            </section>

            <section class="space-y-3">
                <h2 class="text-lg font-semibold">Example options response</h2>
                <p class="text-sm text-neutral-600">
                    <code>GET {{ endpoint }}/options</code>
                </p>
                <pre
                    class="overflow-x-auto rounded border bg-white px-4 py-3 text-sm"
                >{{ sampleOptionsResponse }}</pre>
            </section>
        </div>
    </ApiTokensShell>
</template>
