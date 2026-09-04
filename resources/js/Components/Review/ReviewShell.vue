<script setup>
    import { Link } from '@inertiajs/vue3';

    let props = defineProps({
        activeTab: {
            type: String,
            required: true,
        },
    });

    const tabs = [
        { id: 'leftover', href: '/review', label: 'Leftover' },
        { id: 'sunday', href: '/review/sunday', label: 'Sunday' },
    ];
</script>

<template>
    <div class="space-y-6">
        <div class="flex flex-wrap gap-2 border-b pb-2">
            <Link
                v-for="tab in tabs"
                :key="tab.id"
                :href="tab.href"
                class="rounded px-3 py-1.5 text-sm"
                :class="
                    props.activeTab === tab.id
                        ? 'bg-brand hover:bg-brand-hover text-white'
                        : 'text-neutral-700 hover:bg-neutral-100'
                "
            >
                {{ tab.label }}
            </Link>
        </div>

        <slot />
    </div>
</template>
