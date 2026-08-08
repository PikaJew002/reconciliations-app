<script setup>
    import AuthenticatedLayout from '../../../Layouts/AuthenticatedLayout.vue';
    import { useForm } from '@inertiajs/vue3';

    defineOptions({ layout: AuthenticatedLayout });

    let form = useForm({
        summary_file: null,
        items_file: null,
    });

    let submit = () => {
        form.post('/imports/amazon-orders', {
            forceFormData: true,
        });
    };
</script>

<template>
    <div class="space-y-6">
        <div>
            <h1 class="text-2xl font-semibold">Import Amazon orders</h1>
            <p class="text-sm text-neutral-600">
                Upload both Amazon order history CSVs together: order summary and
                item details.
            </p>
        </div>

        <form class="space-y-4" @submit.prevent="submit">
            <div>
                <label class="mb-1 block text-sm" for="summary_file">
                    Order summary CSV
                </label>
                <input
                    id="summary_file"
                    type="file"
                    accept=".csv,text/csv"
                    class="w-full text-sm"
                    required
                    @input="form.summary_file = $event.target.files[0]"
                />
                <p
                    v-if="form.errors.summary_file"
                    class="mt-1 text-sm text-red-600"
                >
                    {{ form.errors.summary_file }}
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
                    class="w-full text-sm"
                    required
                    @input="form.items_file = $event.target.files[0]"
                />
                <p
                    v-if="form.errors.items_file"
                    class="mt-1 text-sm text-red-600"
                >
                    {{ form.errors.items_file }}
                </p>
            </div>

            <button
                type="submit"
                class="rounded bg-neutral-900 px-4 py-2 text-white disabled:opacity-50"
                :disabled="form.processing"
            >
                Queue import
            </button>
        </form>
    </div>
</template>
