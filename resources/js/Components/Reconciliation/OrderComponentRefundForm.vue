<script setup>
    import { formatMoney } from '../../Composables/useReconciliationFormatting.js';
    import { router } from '@inertiajs/vue3';
    import { ref, watch } from 'vue';

    let props = defineProps({
        orderId: {
            type: Number,
            required: true,
        },
        component: {
            type: Object,
            required: true,
        },
    });

    let editing = ref(false);
    let refundAmount = ref(defaultAmount(props.component));
    let refundKind = ref(props.component.refund_kind || 'bank');
    let saving = ref(false);

    function defaultAmount(component) {
        if (component.refund_amount != null) {
            return Number(component.refund_amount);
        }

        return Number(component.amount);
    }

    watch(
        () => props.component,
        (component) => {
            refundAmount.value = defaultAmount(component);
            refundKind.value = component.refund_kind || 'bank';
        },
    );

    function refundLabel(kind) {
        return kind === 'off_book' ? 'store credit' : 'bank credit';
    }

    function saveRefund() {
        if (!props.component.can_refund || saving.value) {
            return;
        }

        saving.value = true;

        router.patch(
            `/reconciliation/orders/${props.orderId}/components/${props.component.id}/refund`,
            {
                refund_amount: refundAmount.value,
                refund_kind: refundKind.value,
            },
            {
                preserveScroll: true,
                onSuccess: () => {
                    editing.value = false;
                },
                onFinish: () => {
                    saving.value = false;
                },
            },
        );
    }

    function clearRefund() {
        if (!props.component.can_refund || saving.value) {
            return;
        }

        saving.value = true;

        router.delete(
            `/reconciliation/orders/${props.orderId}/components/${props.component.id}/refund`,
            {
                preserveScroll: true,
                onSuccess: () => {
                    editing.value = false;
                },
                onFinish: () => {
                    saving.value = false;
                },
            },
        );
    }
</script>

<template>
    <div class="space-y-1">
        <p
            v-if="component.refund_kind && !editing"
            class="text-xs text-amber-800"
        >
            Refunded {{ formatMoney(component.refund_amount) }} to
            {{ refundLabel(component.refund_kind) }}
            <button
                v-if="component.can_refund"
                type="button"
                class="ml-2 underline disabled:opacity-50"
                :disabled="saving"
                @click="editing = true"
            >
                Edit
            </button>
            <button
                v-if="component.can_refund"
                type="button"
                class="ml-2 underline disabled:opacity-50"
                :disabled="saving"
                @click="clearRefund"
            >
                Clear
            </button>
        </p>
        <form
            v-else-if="component.can_refund && (editing || !component.refund_kind)"
            class="flex flex-wrap items-center gap-2"
            @submit.prevent="saveRefund"
        >
            <button
                v-if="!editing && !component.refund_kind"
                type="button"
                class="text-xs text-neutral-800 underline"
                @click="editing = true"
            >
                Refund
            </button>
            <template v-if="editing">
                <label class="flex items-center gap-1.5 text-neutral-600">
                    <span>Amount</span>
                    <input
                        v-model.number="refundAmount"
                        type="number"
                        min="0.01"
                        step="0.01"
                        class="w-24 rounded border px-2"
                        required
                    />
                </label>
                <select v-model="refundKind" class="rounded border px-2 text-xs">
                    <option value="bank">Bank credit</option>
                    <option value="off_book">Store credit</option>
                </select>
                <button
                    type="submit"
                    class="text-xs text-neutral-800 underline disabled:opacity-50"
                    :disabled="saving"
                >
                    {{ saving ? 'Saving…' : 'Save refund' }}
                </button>
                <button
                    type="button"
                    class="text-xs text-neutral-600 underline"
                    @click="editing = false"
                >
                    Cancel
                </button>
            </template>
        </form>
        <p v-if="editing && refundKind === 'off_book'" class="text-xs text-neutral-600">
            Store credit stays in the bank total because the card was not
            credited. Set the bank total to the component sum so it matches
            the charge. Include tax in the refund amount if you do not want to
            edit the sales tax line.
        </p>
        <p v-else-if="editing && refundKind === 'bank'" class="text-xs text-neutral-600">
            A bank credit lowers the payable total, including tax if you enter
            more than this component.
        </p>
    </div>
</template>
