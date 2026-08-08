<script setup>
    import AuthenticatedLayout from '../../../Layouts/AuthenticatedLayout.vue';
    import { useForm } from '@inertiajs/vue3';

    defineOptions({ layout: AuthenticatedLayout });

    let form = useForm({
        file: null,
    });

    let submit = () => {
        form.post('/imports/walmart-orders', {
            forceFormData: true,
        });
    };
</script>

<template>
    <div class="space-y-6">
        <div>
            <h1 class="text-2xl font-semibold">Import Walmart orders</h1>
            <p class="text-sm text-neutral-600">
                Upload a Walmart orders JSON export.
            </p>
        </div>

        <form class="space-y-4" @submit.prevent="submit">
            <div>
                <label class="mb-1 block text-sm" for="file">JSON file</label>
                <input
                    id="file"
                    type="file"
                    accept=".json,application/json"
                    class="w-full text-sm"
                    required
                    @input="form.file = $event.target.files[0]"
                />
                <p v-if="form.errors.file" class="mt-1 text-sm text-red-600">
                    {{ form.errors.file }}
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
