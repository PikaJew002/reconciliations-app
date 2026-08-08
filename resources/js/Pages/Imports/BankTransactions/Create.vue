<script setup>
    import AuthenticatedLayout from '../../../Layouts/AuthenticatedLayout.vue';
    import { useForm } from '@inertiajs/vue3';

    defineOptions({ layout: AuthenticatedLayout });

    defineProps({
        accounts: {
            type: Array,
            required: true,
        },
    });

    let form = useForm({
        account_id: '',
        file: null,
    });

    let submit = () => {
        form.post('/imports/bank-transactions', {
            forceFormData: true,
        });
    };
</script>

<template>
    <div class="space-y-6">
        <div>
            <h1 class="text-2xl font-semibold">Import bank transactions</h1>
            <p class="text-sm text-neutral-600">
                Upload a CSV. Column mapping will be added later.
            </p>
        </div>

        <form class="space-y-4" @submit.prevent="submit">
            <div>
                <label class="mb-1 block text-sm" for="account_id"
                    >Account</label
                >
                <select
                    id="account_id"
                    v-model="form.account_id"
                    class="w-full rounded border px-3 py-2"
                    required
                >
                    <option disabled value="">Select an account</option>
                    <option
                        v-for="account in accounts"
                        :key="account.id"
                        :value="account.id"
                    >
                        {{ account.name }}
                        <template v-if="account.last_four">
                            (•••• {{ account.last_four }})</template
                        >
                    </option>
                </select>
                <p
                    v-if="form.errors.account_id"
                    class="mt-1 text-sm text-red-600"
                >
                    {{ form.errors.account_id }}
                </p>
            </div>

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
