<script setup>
    import AuthenticatedLayout from '../../Layouts/AuthenticatedLayout.vue';
    import { Link, router } from '@inertiajs/vue3';
    import { computed, onMounted, onUnmounted } from 'vue';

    defineOptions({ layout: AuthenticatedLayout });

    let props = defineProps({
        batch: {
            type: Object,
            required: true,
        },
        backHref: {
            type: String,
            required: true,
        },
        backLabel: {
            type: String,
            required: true,
        },
    });

    let isInProgress = computed(() =>
        ['pending', 'processing'].includes(props.batch.status),
    );
    let pollId = null;

    onMounted(() => {
        if (!isInProgress.value) {
            return;
        }

        pollId = window.setInterval(() => {
            router.reload({
                only: ['batch'],
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
                <Link :href="backHref" class="underline">{{ backLabel }}</Link>
                /
                Import batch
            </p>
            <h1 class="mt-2 text-2xl font-semibold">Import batch</h1>
            <p class="text-sm text-neutral-600">
                {{ batch.original_filename }}
            </p>
        </div>

        <dl class="space-y-3 rounded border p-4 text-sm">
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
    </div>
</template>
