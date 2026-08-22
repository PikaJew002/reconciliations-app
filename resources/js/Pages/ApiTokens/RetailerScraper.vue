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
        statusEndpoint: {
            type: String,
            required: true,
        },
        plainTextToken: {
            type: String,
            default: null,
        },
    });

    let form = useForm({
        name: 'Retailer scraper',
    });

    let samplePayload = `{
  "orderNumbers": ["111-2223333-4445555"]
}`;

    let submit = () => {
        form.post('/api-tokens/retailer-scraper');
    };
</script>

<template>
    <ApiTokensShell active-tab="retailer-scraper">
        <div class="space-y-8">
            <ApiTokenPlainText :token="plainTextToken" />

            <section class="space-y-3">
                <h2 class="text-lg font-semibold">Create retailer scraper token</h2>
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
                    The Chrome extension mints its own token when you sign in from
                    the extension. Minting a token with an existing label replaces
                    the old one. Ability:
                    <code>amazon:import</code>
                </p>
            </section>

            <ApiTokenList :tokens="tokens" />

            <section class="space-y-3">
                <h2 class="text-lg font-semibold">Scraper request</h2>
                <p class="text-sm text-neutral-600">
                    <code>POST {{ endpoint }}</code>
                    queues scraped Amazon order JSON. Then
                    <code>POST {{ statusEndpoint }}</code>
                    checks which order numbers are already imported.
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
