<script setup>
    import AuthenticatedLayout from '../../Layouts/AuthenticatedLayout.vue';
    import { Link, useForm, usePage } from '@inertiajs/vue3';
    import { computed } from 'vue';

    defineOptions({ layout: AuthenticatedLayout });

    let props = defineProps({
        merchant: {
            type: Object,
            required: true,
        },
        batches: {
            type: Array,
            required: true,
        },
    });

    let page = usePage();
    let flashSuccess = computed(() => page.props.flash?.success);
    let isAmazon = computed(() => props.merchant.normalized_name === 'amazon');

    let walmartForm = useForm({
        file: null,
    });

    let submitWalmart = () => {
        walmartForm.post(`/orders/${props.merchant.normalized_name}/imports`, {
            forceFormData: true,
        });
    };

    let formatImportedAt = (value) => {
        if (!value) {
            return '—';
        }

        let date = new Date(value);

        if (Number.isNaN(date.getTime())) {
            return value;
        }

        return date.toLocaleString();
    };
</script>

<template>
    <div class="space-y-6">
        <div>
            <p class="text-sm text-neutral-600">
                <Link href="/orders" class="underline">Orders</Link>
                /
                <Link
                    :href="`/orders/${merchant.normalized_name}`"
                    class="underline"
                    >{{ merchant.name }}</Link
                >
                /
                Imports
            </p>
            <h1 class="mt-2 text-2xl font-semibold">
                Import {{ merchant.name }} orders
            </h1>
            <p
                v-if="isAmazon"
                class="text-sm text-neutral-600"
                data-tour="import-amazon-history"
            >
                Amazon orders arrive from the Chrome extension. Import
                history is listed below. See
                <Link href="/api-tokens/retailer-scraper" class="underline">
                    retailer scraper API tokens</Link>.
            </p>
            <p v-else class="text-sm text-neutral-600">
                Upload a Walmart orders JSON export.
            </p>
        </div>

        <p
            v-if="flashSuccess"
            class="rounded border border-green-200 bg-green-50 px-3 py-2 text-sm text-green-800"
        >
            {{ flashSuccess }}
        </p>

        <form
            v-if="!isAmazon"
            class="space-y-4"
            data-tour="import-walmart-form"
            @submit.prevent="submitWalmart"
        >
            <div>
                <label class="mb-1 block text-sm" for="file">JSON file</label>
                <input
                    id="file"
                    type="file"
                    accept=".json,application/json"
                    class="w-full text-sm file:mr-4 file:rounded file:border-0 file:bg-brand file:px-4 file:inline-flex file:h-10 file:items-center file:text-sm file:font-medium file:text-white hover:file:bg-brand-hover"
                    required
                    @input="walmartForm.file = $event.target.files[0]"
                />
                <p
                    v-if="walmartForm.errors.file"
                    class="mt-1 text-sm text-red-600"
                >
                    {{ walmartForm.errors.file }}
                </p>
            </div>

            <button
                type="submit"
                class="btn rounded bg-brand hover:bg-brand-hover px-4 text-white disabled:opacity-50"
                :disabled="walmartForm.processing"
            >
                Queue import
            </button>
        </form>

        <section class="space-y-3">
            <h2 class="text-lg font-semibold">Import history</h2>

            <div v-if="batches.length === 0" class="text-sm text-neutral-600">
                No import batches for {{ merchant.name }} yet.
            </div>

            <ul v-else class="divide-y rounded border">
                <li v-for="batch in batches" :key="batch.id" class="px-4 py-3">
                    <Link
                        :href="`/orders/${merchant.normalized_name}/imports/${batch.id}`"
                        class="block"
                    >
                        <div class="flex items-center justify-between gap-4">
                            <div>
                                <p class="font-medium">
                                    {{ batch.original_filename }}
                                </p>
                                <p class="text-sm text-neutral-600">
                                    {{ batch.source }} / {{ batch.type }}
                                </p>
                                <p class="text-sm text-neutral-600">
                                    Imported {{ formatImportedAt(batch.created_at) }}
                                </p>
                            </div>
                            <div class="text-right text-sm">
                                <p>{{ batch.status }}</p>
                                <p class="text-neutral-600">
                                    {{ batch.record_count }} records
                                </p>
                            </div>
                        </div>
                    </Link>
                </li>
            </ul>
        </section>
    </div>
</template>
