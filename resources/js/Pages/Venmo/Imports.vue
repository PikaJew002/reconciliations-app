<script setup>
    import AuthenticatedLayout from '../../Layouts/AuthenticatedLayout.vue';
    import { Link, router, useForm, usePage } from '@inertiajs/vue3';
    import { computed, ref } from 'vue';

    defineOptions({ layout: AuthenticatedLayout });

    defineProps({
        batches: {
            type: Array,
            required: true,
        },
    });

    let page = usePage();
    let flashSuccess = computed(() => page.props.flash?.success);
    let flashError = computed(() => page.props.flash?.error);
    let revertingId = ref(null);

    let form = useForm({
        file: null,
    });

    let submit = () => {
        form.post('/venmo/imports', {
            forceFormData: true,
        });
    };

    let canRevert = (batch) => batch.can_revert === true;

    let revertBatch = (batch) => {
        if (!canRevert(batch)) {
            return;
        }

        if (
            !window.confirm(
                `Revert import "${batch.original_filename}"? This removes the imported records and undoes their matches.`,
            )
        ) {
            return;
        }

        revertingId.value = batch.id;
        router.delete(`/venmo/imports/${batch.id}`, {
            onFinish: () => {
                revertingId.value = null;
            },
        });
    };
</script>

<template>
    <div class="space-y-6">
        <div>
            <p class="text-sm text-neutral-600">
                <Link href="/accounts" class="underline">Accounts</Link>
                / Venmo
            </p>
            <h1 class="mt-2 text-2xl font-semibold">Import Venmo statement</h1>
            <p class="text-sm text-neutral-600">
                Upload a Venmo account statement CSV. This labels existing bank
                charges and cashouts — it does not add a Venmo account.
            </p>
        </div>

        <p
            v-if="flashSuccess"
            class="rounded border border-green-200 bg-green-50 px-3 py-2 text-sm text-green-800"
        >
            {{ flashSuccess }}
        </p>
        <p
            v-if="flashError"
            class="rounded border border-red-200 bg-red-50 px-3 py-2 text-sm text-red-800"
        >
            {{ flashError }}
        </p>

        <form class="space-y-4" @submit.prevent="submit">
            <div>
                <label class="mb-1 block text-sm" for="file">CSV file</label>
                <input
                    id="file"
                    type="file"
                    accept=".csv,text/csv"
                    class="w-full text-sm file:mr-4 file:rounded file:border-0 file:bg-brand file:px-4 file:inline-flex file:h-10 file:items-center file:text-sm file:font-medium file:text-white hover:file:bg-brand-hover"
                    required
                    @input="form.file = $event.target.files[0]"
                />
                <p v-if="form.errors.file" class="mt-1 text-sm text-red-600">
                    {{ form.errors.file }}
                </p>
            </div>

            <button
                type="submit"
                class="btn rounded bg-brand hover:bg-brand-hover px-4 text-white disabled:opacity-50"
                :disabled="form.processing"
            >
                Queue import
            </button>
        </form>

        <section class="space-y-3">
            <h2 class="text-lg font-semibold">Import history</h2>

            <div v-if="batches.length === 0" class="text-sm text-neutral-600">
                No Venmo statement imports yet.
            </div>

            <ul v-else class="divide-y rounded border">
                <li
                    v-for="batch in batches"
                    :key="batch.id"
                    class="flex flex-wrap items-center justify-between gap-3 px-4 py-3"
                >
                    <Link
                        :href="`/venmo/imports/${batch.id}`"
                        class="min-w-0 flex-1"
                    >
                        <p class="font-medium">
                            {{ batch.original_filename }}
                        </p>
                        <p class="text-sm text-neutral-600">
                            {{ batch.source }} / {{ batch.type }}
                            <template
                                v-if="batch.date_range && (batch.date_range.min || batch.date_range.max)"
                            >
                                · {{ batch.date_range.min }} → {{ batch.date_range.max }}
                            </template>
                        </p>
                    </Link>
                    <div class="flex items-center gap-3">
                        <div class="text-right text-sm">
                            <p>{{ batch.status }}</p>
                            <p class="text-neutral-600">
                                {{ batch.record_count }} records
                            </p>
                        </div>
                        <button
                            v-if="canRevert(batch)"
                            type="button"
                            class="btn rounded border px-3 text-sm text-red-700 hover:bg-red-50"
                            :disabled="revertingId === batch.id"
                            @click="revertBatch(batch)"
                        >
                            Revert
                        </button>
                    </div>
                </li>
            </ul>
        </section>
    </div>
</template>
