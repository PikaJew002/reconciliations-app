<script setup>
    import { formatMoney } from '../../Composables/useReconciliationFormatting.js';
    import { router } from '@inertiajs/vue3';
    import { ref, watch } from 'vue';

    let props = defineProps({
        orderId: {
            type: Number,
            required: true,
        },
        total: {
            type: Number,
            required: true,
        },
        importedTotal: {
            type: Number,
            required: true,
        },
        componentSum: {
            type: Number,
            required: true,
        },
        canEdit: {
            type: Boolean,
            default: false,
        },
    });

    let bankTotal = ref(Number(props.total));
    let saving = ref(false);

    watch(
        () => props.total,
        (value) => {
            bankTotal.value = Number(value);
        },
    );

    function saveTotal(amount) {
        if (!props.canEdit || saving.value) {
            return;
        }

        saving.value = true;

        router.patch(
            `/reconciliation/orders/${props.orderId}/total`,
            { total: amount },
            {
                preserveScroll: true,
                onFinish: () => {
                    saving.value = false;
                },
            },
        );
    }
</script>

<template>
    <form class="space-y-2 text-sm" @submit.prevent="saveTotal(bankTotal)">
        <div class="flex flex-wrap items-end gap-3">
            <p class="text-neutral-600">
                Imported total
                <span class="font-medium text-neutral-900">{{
                    formatMoney(importedTotal)
                }}</span>
            </p>
            <label class="flex items-center gap-1.5">
                <span class="text-neutral-600">Bank total</span>
                <input
                    v-model.number="bankTotal"
                    type="number"
                    min="0"
                    step="0.01"
                    class="w-28 rounded border px-2"
                    :disabled="!canEdit || saving"
                    required
                />
            </label>
            <button
                type="submit"
                class="text-xs text-neutral-800 underline disabled:opacity-50"
                :disabled="!canEdit || saving"
            >
                {{ saving ? 'Saving…' : 'Save bank total' }}
            </button>
            <button
                type="button"
                class="text-xs text-neutral-800 underline disabled:opacity-50"
                :disabled="!canEdit || saving"
                @click="saveTotal(componentSum)"
            >
                Set bank total to component sum
            </button>
        </div>
        <p class="text-neutral-600">
            Bank total is what reconciliation matches. Imported total stays as
            reported.
        </p>
    </form>
</template>
