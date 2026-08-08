<script setup>
    import AuthenticatedLayout from '../../Layouts/AuthenticatedLayout.vue';
    import { Link, usePage } from '@inertiajs/vue3';
    import { computed } from 'vue';

    defineOptions({ layout: AuthenticatedLayout });

    defineProps({
        batches: {
            type: Array,
            required: true,
        },
    });

    let page = usePage();
    let flashSuccess = computed(() => page.props.flash?.success);
</script>

<template>
    <div class="space-y-6">
        <div>
            <h1 class="text-2xl font-semibold">Imports</h1>
            <p class="text-sm text-neutral-600">
                Import bank transactions and Walmart orders.
            </p>
        </div>

        <p
            v-if="flashSuccess"
            class="rounded border border-green-200 bg-green-50 px-3 py-2 text-sm text-green-800"
        >
            {{ flashSuccess }}
        </p>

        <div class="flex flex-wrap gap-3">
            <Link
                href="/imports/bank-transactions/create"
                class="rounded bg-neutral-900 px-4 py-2 text-sm text-white"
            >
                Import bank transactions
            </Link>
            <Link
                href="/imports/walmart-orders/create"
                class="rounded border px-4 py-2 text-sm"
            >
                Import Walmart orders
            </Link>
        </div>

        <div v-if="batches.length === 0" class="text-sm text-neutral-600">
            No import batches yet.
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
    </div>
</template>
