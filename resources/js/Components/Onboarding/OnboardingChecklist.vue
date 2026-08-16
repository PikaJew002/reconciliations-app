<script setup>
    import { Link } from '@inertiajs/vue3';

    defineProps({
        steps: {
            type: Array,
            required: true,
        },
        stepHref: {
            type: Function,
            required: true,
        },
    });

    let emit = defineEmits(['hide', 'skip']);
</script>

<template>
    <section
        class="rounded border bg-[color-mix(in_srgb,var(--color-brand)_5%,white)] px-4 py-3"
    >
        <div class="flex flex-wrap items-start justify-between gap-3">
            <div>
                <h2 class="text-sm font-semibold">Getting started</h2>
                <p class="text-sm text-mute">
                    Add your accounts, import recent bank activity, optionally
                    add Amazon or Walmart orders, then categorize transactions.
                </p>
            </div>
            <button
                type="button"
                class="btn rounded border px-3 text-sm hover:bg-brand-wash"
                @click="emit('hide')"
            >
                Hide setup
            </button>
        </div>

        <ol class="mt-4 space-y-3">
            <li
                v-for="step in steps"
                :key="step.key"
                class="flex flex-wrap items-start justify-between gap-3 border-t pt-3 first:border-t-0 first:pt-0"
            >
                <div class="min-w-0 flex-1">
                    <p
                        class="text-sm font-medium"
                        :class="
                            step.complete
                                ? 'text-neutral-500 line-through'
                                : 'text-neutral-900'
                        "
                    >
                        {{ step.title }}
                    </p>
                    <p class="text-sm text-neutral-600">
                        {{ step.description }}
                    </p>
                </div>
                <div
                    v-if="!step.complete"
                    class="flex shrink-0 flex-wrap items-center gap-2"
                >
                    <Link
                        :href="stepHref(step)"
                        class="btn rounded bg-brand hover:bg-brand-hover px-3 text-sm text-white"
                    >
                        {{ step.cta }}
                    </Link>
                    <button
                        v-if="step.skippable"
                        type="button"
                        class="btn rounded border px-3 text-sm hover:bg-brand-wash"
                        @click="emit('skip', step)"
                    >
                        Skip
                    </button>
                </div>
                <p v-else class="shrink-0 text-sm font-medium text-brand">Done</p>
            </li>
        </ol>
    </section>
</template>
