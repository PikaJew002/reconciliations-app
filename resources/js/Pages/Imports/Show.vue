<script setup>
    import AuthenticatedLayout from '../../Layouts/AuthenticatedLayout.vue';
    import { Link, router, usePage } from '@inertiajs/vue3';
    import { computed, onMounted, onUnmounted, ref } from 'vue';

    defineOptions({ layout: AuthenticatedLayout });

    let props = defineProps({
        batch: {
            type: Object,
            required: true,
        },
        breadcrumbs: {
            type: Array,
            required: true,
        },
        can_revert: {
            type: Boolean,
            default: false,
        },
        revert_url: {
            type: String,
            default: null,
        },
    });

    let page = usePage();
    let flashSuccess = computed(() => page.props.flash?.success);
    let flashError = computed(() => page.props.flash?.error);
    let isInProgress = computed(() =>
        ['pending', 'processing'].includes(props.batch.status),
    );
    let reverting = ref(false);
    let pollId = null;

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

    let revertBatch = () => {
        if (!props.can_revert || !props.revert_url) {
            return;
        }

        if (
            !window.confirm(
                `Revert import "${props.batch.original_filename}"? This removes the imported records and undoes their matches.`,
            )
        ) {
            return;
        }

        reverting.value = true;
        router.delete(props.revert_url, {
            onFinish: () => {
                reverting.value = false;
            },
        });
    };

    onMounted(() => {
        if (!isInProgress.value) {
            return;
        }

        pollId = window.setInterval(() => {
            router.reload({
                only: ['batch', 'can_revert', 'revert_url'],
                onSuccess: (page) => {
                    let status = page.props.batch?.status;
                    if (
                        status &&
                        !['pending', 'processing'].includes(status) &&
                        pollId
                    ) {
                        window.clearInterval(pollId);
                        pollId = null;
                    }
                },
            });
        }, 2000);
    });

    onUnmounted(() => {
        if (pollId) {
            window.clearInterval(pollId);
        }
    });
</script>

<template>
    <div class="space-y-6">
        <div>
            <p class="text-sm text-neutral-600">
                <template
                    v-for="(crumb, index) in breadcrumbs"
                    :key="`${crumb.label}-${index}`"
                >
                    <span v-if="index > 0"> / </span>
                    <Link
                        v-if="crumb.href"
                        :href="crumb.href"
                        class="underline"
                        >{{ crumb.label }}</Link
                    >
                    <template v-else>{{ crumb.label }}</template>
                </template>
            </p>
            <h1 class="mt-2 text-2xl font-semibold">Import batch</h1>
            <p class="text-sm text-neutral-600">
                {{ batch.original_filename }}
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

        <dl class="space-y-3 rounded border p-4 text-sm">
            <div class="flex justify-between gap-4">
                <dt class="text-neutral-600">Imported</dt>
                <dd>{{ formatImportedAt(batch.created_at) }}</dd>
            </div>
            <div class="flex justify-between gap-4">
                <dt class="text-neutral-600">Source</dt>
                <dd>{{ batch.source }}</dd>
            </div>
            <div class="flex justify-between gap-4">
                <dt class="text-neutral-600">Type</dt>
                <dd>{{ batch.type }}</dd>
            </div>
            <div class="flex justify-between gap-4">
                <dt class="text-neutral-600">Status</dt>
                <dd>{{ batch.status }}</dd>
            </div>
            <div class="flex justify-between gap-4">
                <dt class="text-neutral-600">Records</dt>
                <dd>{{ batch.record_count }}</dd>
            </div>
            <div v-if="batch.error_message" class="space-y-1">
                <dt class="text-neutral-600">Error</dt>
                <dd class="text-red-600">{{ batch.error_message }}</dd>
            </div>
        </dl>

        <p v-if="isInProgress" class="text-sm text-neutral-600">
            Processing… this page refreshes automatically.
        </p>

        <div v-if="can_revert && revert_url" class="space-y-2">
            <button
                type="button"
                class="btn rounded border px-4 text-sm text-red-700 hover:bg-red-50"
                :disabled="reverting"
                @click="revertBatch"
            >
                Revert import
            </button>
            <p class="text-sm text-neutral-600">
                Removes the records created by this import and undoes their
                matches.
            </p>
        </div>
    </div>
</template>
