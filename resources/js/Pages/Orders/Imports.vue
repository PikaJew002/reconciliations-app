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

    let amazonForm = useForm({
        summary_file: null,
        items_file: null,
    });

    let submitWalmart = () => {
        walmartForm.post(`/orders/${props.merchant.normalized_name}/imports`, {
            forceFormData: true,
        });
    };

    let submitAmazon = () => {
        amazonForm.post(`/orders/${props.merchant.normalized_name}/imports`, {
            forceFormData: true,
        });
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
            <p class="text-sm text-neutral-600">
                <template v-if="isAmazon">
                    Upload both Amazon order history CSVs together: order
                    summary and item details.
                </template>
                <template v-else>
                    Upload a Walmart orders JSON export.
                </template>
            </p>
        </div>

        <p
            v-if="flashSuccess"
            class="rounded border border-green-200 bg-green-50 px-3 py-2 text-sm text-green-800"
        >
            {{ flashSuccess }}
        </p>

        <form
            v-if="isAmazon"
            class="space-y-4"
            @submit.prevent="submitAmazon"
        >
            <div>
                <label class="mb-1 block text-sm" for="summary_file">
                    Order summary CSV
                </label>
                <input
                    id="summary_file"
                    type="file"
                    accept=".csv,text/csv"
                    class="w-full text-sm file:mr-4 file:rounded file:border-0 file:bg-neutral-900 file:px-4 file:py-2 file:text-sm file:font-medium file:text-white hover:file:bg-neutral-800"
                    required
                    @input="amazonForm.summary_file = $event.target.files[0]"
                />
                <p
                    v-if="amazonForm.errors.summary_file"
                    class="mt-1 text-sm text-red-600"
                >
                    {{ amazonForm.errors.summary_file }}
                </p>
            </div>

            <div>
                <label class="mb-1 block text-sm" for="items_file">
                    Item details CSV
                </label>
                <input
                    id="items_file"
                    type="file"
                    accept=".csv,text/csv"
                    class="w-full text-sm file:mr-4 file:rounded file:border-0 file:bg-neutral-900 file:px-4 file:py-2 file:text-sm file:font-medium file:text-white hover:file:bg-neutral-800"
                    required
                    @input="amazonForm.items_file = $event.target.files[0]"
                />
                <p
                    v-if="amazonForm.errors.items_file"
                    class="mt-1 text-sm text-red-600"
                >
                    {{ amazonForm.errors.items_file }}
                </p>
            </div>

            <button
                type="submit"
                class="rounded bg-neutral-900 px-4 py-2 text-white disabled:opacity-50"
                :disabled="amazonForm.processing"
            >
                Queue import
            </button>
        </form>

        <form v-else class="space-y-4" @submit.prevent="submitWalmart">
            <div>
                <label class="mb-1 block text-sm" for="file">JSON file</label>
                <input
                    id="file"
                    type="file"
                    accept=".json,application/json"
                    class="w-full text-sm file:mr-4 file:rounded file:border-0 file:bg-neutral-900 file:px-4 file:py-2 file:text-sm file:font-medium file:text-white hover:file:bg-neutral-800"
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
                class="rounded bg-neutral-900 px-4 py-2 text-white disabled:opacity-50"
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
                    <Link :href="`/imports/${batch.id}`" class="block">
                        <div class="flex items-center justify-between gap-4">
                            <div>
                                <p class="font-medium">
                                    {{ batch.original_filename }}
                                </p>
                                <p class="text-sm text-neutral-600">
                                    {{ batch.source }} / {{ batch.type }}
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
