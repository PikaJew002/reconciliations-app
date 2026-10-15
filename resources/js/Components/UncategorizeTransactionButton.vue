<script setup>
    import { router } from '@inertiajs/vue3';
    import { ref } from 'vue';

    let props = defineProps({
        transactionId: {
            type: Number,
            required: true,
        },
    });

    let releasing = ref(false);

    function uncategorize() {
        if (releasing.value) {
            return;
        }

        if (
            !window.confirm(
                'Release this transaction from its category? It goes back to unmatched so you can categorize it again or match it to an order. Automatic rules will skip it until you categorize it again.',
            )
        ) {
            return;
        }

        releasing.value = true;

        router.delete(
            `/reconciliation/transactions/${props.transactionId}/categorize`,
            {
                preserveScroll: true,
                onFinish: () => {
                    releasing.value = false;
                },
            },
        );
    }
</script>

<template>
    <button
        type="button"
        class="btn rounded border px-3 text-xs text-neutral-700 hover:bg-neutral-100"
        :disabled="releasing"
        @click="uncategorize"
    >
        Uncategorize
    </button>
</template>
