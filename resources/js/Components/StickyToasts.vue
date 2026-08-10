<script setup>
    defineProps({
        toasts: {
            type: Array,
            default: () => [],
        },
    });

    defineEmits(['dismiss']);

    function toastClasses(type) {
        if (type === 'error') {
            return 'border-red-200 bg-red-50 text-red-800';
        }

        if (type === 'warning') {
            return 'border-amber-200 bg-amber-50 text-amber-900';
        }

        return 'border-green-200 bg-green-50 text-green-800';
    }
</script>

<template>
    <div
        aria-live="polite"
        class="pointer-events-none fixed inset-x-0 top-0 z-50 flex flex-col items-center gap-2 px-4 pt-4"
    >
        <TransitionGroup name="sticky-toast">
            <div
                v-for="toast in toasts"
                :key="toast.id"
                class="pointer-events-auto flex w-full max-w-3xl items-start gap-3 rounded border px-3 py-2 text-sm shadow-md"
                :class="toastClasses(toast.type)"
            >
                <p class="min-w-0 flex-1">{{ toast.message }}</p>
                <button
                    type="button"
                    class="shrink-0 text-current/70 hover:text-current"
                    :aria-label="'Dismiss notification'"
                    @click="$emit('dismiss', toast.id)"
                >
                    ×
                </button>
            </div>
        </TransitionGroup>
    </div>
</template>

<style scoped>
    .sticky-toast-enter-active,
    .sticky-toast-leave-active {
        transition:
            opacity 0.2s ease,
            transform 0.2s ease;
    }

    .sticky-toast-enter-from,
    .sticky-toast-leave-to {
        opacity: 0;
        transform: translateY(-0.5rem);
    }

    .sticky-toast-move {
        transition: transform 0.2s ease;
    }
</style>
