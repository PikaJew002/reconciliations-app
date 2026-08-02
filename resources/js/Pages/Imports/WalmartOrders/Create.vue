<script setup>
import { Link, useForm } from '@inertiajs/vue3';

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
    <div class="mx-auto max-w-lg space-y-6 p-8">
        <div>
            <Link href="/imports" class="text-sm underline">Back to imports</Link>
            <h1 class="mt-2 text-2xl font-semibold">Import Walmart orders</h1>
            <p class="text-sm text-neutral-600">Upload a CSV. Column mapping will be added later.</p>
        </div>

        <form class="space-y-4" @submit.prevent="submit">
            <div>
                <label class="mb-1 block text-sm" for="file">CSV file</label>
                <input
                    id="file"
                    type="file"
                    accept=".csv,text/csv"
                    class="w-full text-sm"
                    required
                    @input="form.file = $event.target.files[0]"
                />
                <p v-if="form.errors.file" class="mt-1 text-sm text-red-600">{{ form.errors.file }}</p>
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
