<script setup>
    import AuthenticatedLayout from '../../Layouts/AuthenticatedLayout.vue';
    import { Link, useForm, usePage } from '@inertiajs/vue3';
    import { computed } from 'vue';

    defineOptions({ layout: AuthenticatedLayout });

    let props = defineProps({
        account: {
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

    let form = useForm({
        file: null,
    });

    let submit = () => {
        form.post(`/accounts/${props.account.id}/imports`, {
            forceFormData: true,
        });
    };
</script>

<template>
    <div class="space-y-6">
        <div>
            <p class="text-sm text-neutral-600">
                <Link href="/accounts" class="underline">Accounts</Link>
                /
                <Link :href="`/accounts/${account.id}`" class="underline">{{
                    account.name
                }}</Link>
                /
                Imports
            </p>
            <h1 class="mt-2 text-2xl font-semibold">Import bank transactions</h1>
            <p class="text-sm text-neutral-600">
                Upload a CSV for {{ account.name }}
                <template v-if="account.last_four">
                    (•••• {{ account.last_four }})</template
                >.
            </p>
        </div>

        <p
            v-if="flashSuccess"
            class="rounded border border-green-200 bg-green-50 px-3 py-2 text-sm text-green-800"
        >
            {{ flashSuccess }}
        </p>

        <form class="space-y-4" @submit.prevent="submit">
            <div>
                <label class="mb-1 block text-sm" for="file">CSV file</label>
                <input
                    id="file"
                    type="file"
                    accept=".csv,text/csv"
                    class="w-full text-sm file:mr-4 file:rounded file:border-0 file:bg-brand file:px-4 file:py-2 file:text-sm file:font-medium file:text-white hover:file:bg-brand-hover"
                    data-tour="import-bank-file"
                    required
                    @input="form.file = $event.target.files[0]"
                />
                <p v-if="form.errors.file" class="mt-1 text-sm text-red-600">
                    {{ form.errors.file }}
                </p>
            </div>

            <button
                type="submit"
                class="rounded bg-brand hover:bg-brand-hover px-4 py-2 text-white disabled:opacity-50"
                data-tour="import-bank-submit"
                :disabled="form.processing"
            >
                Queue import
            </button>
        </form>

        <section class="space-y-3">
            <h2 class="text-lg font-semibold">Import history</h2>

            <div v-if="batches.length === 0" class="text-sm text-neutral-600">
                No import batches for this account yet.
            </div>

            <ul v-else class="divide-y rounded border">
                <li v-for="batch in batches" :key="batch.id" class="px-4 py-3">
                    <Link
                        :href="`/accounts/${account.id}/imports/${batch.id}`"
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
