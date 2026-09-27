<script setup>
    import OrderBankTotalForm from '../../Components/Reconciliation/OrderBankTotalForm.vue';
    import OrderComponentRefundForm from '../../Components/Reconciliation/OrderComponentRefundForm.vue';
    import AuthenticatedLayout from '../../Layouts/AuthenticatedLayout.vue';
    import { formatMoney } from '../../Composables/useReconciliationFormatting.js';
    import { Link, router } from '@inertiajs/vue3';
    import { computed, ref } from 'vue';

    defineOptions({ layout: AuthenticatedLayout });

    let props = defineProps({
        merchant: {
            type: Object,
            required: true,
        },
        order: {
            type: Object,
            required: true,
        },
        items: {
            type: Array,
            required: true,
        },
        components: {
            type: Array,
            required: true,
        },
        can_delete: {
            type: Boolean,
            required: true,
        },
        has_allocations: {
            type: Boolean,
            required: true,
        },
    });

    let deleting = ref(false);

    let gapExplained = computed(() => {
        let importedDiffers =
            props.order.imported_total != null &&
            Math.abs(
                Number(props.order.imported_total) - Number(props.order.total),
            ) >= 0.01;
        let hasRefund = props.components.some(
            (component) => component.refund_kind,
        );

        return importedDiffers || hasRefund;
    });

    let formatDate = (value) => value || '—';

    let formatQuantity = (value) => {
        let quantity = Number(value);

        if (Number.isInteger(quantity)) {
            return String(quantity);
        }

        return quantity.toString();
    };

    let allocationLabel = (component) => {
        if (Math.abs(Number(component.allocated_amount)) < 0.01) {
            return 'Unallocated';
        }

        if (Math.abs(Number(component.remaining_amount)) < 0.01) {
            return 'Fully allocated';
        }

        return `${formatMoney(component.allocated_amount)} allocated`;
    };

    let removeOrder = () => {
        if (!props.can_delete || deleting.value) {
            return;
        }

        let message = `Remove ${props.merchant.name} order ${props.order.order_number}? This deletes the imported order so it can be scraped again.`;

        if (props.has_allocations) {
            message +=
                ' Bank matches for this order will be undone, and those transactions will go back to unmatched.';
        }

        if (!window.confirm(message)) {
            return;
        }

        deleting.value = true;

        router.delete(
            `/orders/${props.merchant.normalized_name}/${props.order.id}`,
            {
                onFinish: () => {
                    deleting.value = false;
                },
            },
        );
    };
</script>

<template>
    <div class="space-y-6">
        <div>
            <p class="text-sm text-neutral-600">
                <Link href="/orders" class="underline">Orders</Link>
                /
                <Link
                    :href="`/orders/${merchant.normalized_name}`"
                    class="underline"
                >
                    {{ merchant.name }}
                </Link>
                /
                {{ order.order_number }}
            </p>
            <div class="mt-1 flex flex-wrap items-start justify-between gap-4">
                <div>
                    <h1 class="text-2xl font-semibold">
                        {{ order.order_number }}
                    </h1>
                    <p class="text-sm text-neutral-600">
                        Ordered {{ formatDate(order.ordered_at) }} · Delivered
                        {{ formatDate(order.delivered_at) }} ·
                        {{ order.status }}
                        <template v-if="order.payment_last_four">
                            · •••• {{ order.payment_last_four }}
                        </template>
                    </p>
                </div>
                <div class="text-right">
                    <p class="text-lg font-semibold">
                        {{ formatMoney(order.total) }}
                    </p>
                    <p class="text-xs text-neutral-600">Bank total</p>
                </div>
            </div>
        </div>

        <p
            v-if="!order.components_balanced"
            class="rounded border border-amber-200 bg-amber-50 px-3 py-2 text-sm text-amber-900"
        >
            Components do not add up to the bank total (components
            {{ formatMoney(order.component_sum) }}, gap
            {{ formatMoney(order.gap) }}).
            <template v-if="gapExplained">
                The imported total is {{ formatMoney(order.imported_total) }}.
                Mark a refunded component or set the bank total. A bank credit
                lowers what the card must net to; store credit does not,
                because the charge was not credited back.
            </template>
            <template v-else>
                This often means the scrape imported the order incorrectly.
                Remove it below to re-import, or mark a refund if a line came
                back.
            </template>
        </p>

        <div class="grid gap-3 sm:grid-cols-2 lg:grid-cols-3">
            <div class="rounded border px-4 py-3 text-sm">
                <p class="text-neutral-600">Subtotal</p>
                <p class="font-medium">{{ formatMoney(order.subtotal) }}</p>
            </div>
            <div class="rounded border px-4 py-3 text-sm">
                <p class="text-neutral-600">Tax</p>
                <p class="font-medium">{{ formatMoney(order.tax) }}</p>
            </div>
            <div class="rounded border px-4 py-3 text-sm">
                <p class="text-neutral-600">Delivery</p>
                <p class="font-medium">
                    {{ formatMoney(order.delivery_fee) }}
                </p>
            </div>
            <div class="rounded border px-4 py-3 text-sm">
                <p class="text-neutral-600">Tip</p>
                <p class="font-medium">{{ formatMoney(order.tip) }}</p>
            </div>
            <div class="rounded border px-4 py-3 text-sm">
                <p class="text-neutral-600">Discount</p>
                <p class="font-medium">{{ formatMoney(order.discount) }}</p>
            </div>
            <div
                class="rounded border px-4 py-3 text-sm"
                :class="
                    order.components_balanced
                        ? ''
                        : 'border-amber-200 bg-amber-50'
                "
            >
                <p class="text-neutral-600">Components</p>
                <p class="font-medium">
                    {{ formatMoney(order.component_sum) }}
                </p>
            </div>
            <div class="rounded border px-4 py-3 text-sm sm:col-span-2 lg:col-span-3">
                <OrderBankTotalForm
                    :order-id="order.id"
                    :total="order.total"
                    :imported-total="order.imported_total"
                    :component-sum="order.component_sum"
                    :can-edit="order.can_edit_total"
                />
            </div>
        </div>

        <section v-if="order.payments.length > 0" class="space-y-3">
            <h2 class="text-base font-semibold">Payments</h2>
            <ul class="divide-y rounded border text-sm">
                <li
                    v-for="(payment, index) in order.payments"
                    :key="`${payment.kind}-${index}`"
                    class="flex items-start justify-between gap-4 px-4 py-3"
                >
                    <div>
                        <p class="font-medium">
                            {{ payment.ending || 'Payment' }}
                        </p>
                        <p class="text-neutral-600">
                            {{ payment.kind || 'unknown' }}
                            <template v-if="payment.last_four">
                                · •••• {{ payment.last_four }}
                            </template>
                        </p>
                    </div>
                    <p v-if="payment.amount != null" class="font-medium">
                        {{ formatMoney(payment.amount) }}
                    </p>
                </li>
            </ul>
        </section>

        <section class="space-y-3">
            <div>
                <h2 class="text-base font-semibold">Items</h2>
                <p class="text-sm text-neutral-600">
                    Product lines imported from {{ merchant.name }}.
                </p>
            </div>
            <p v-if="items.length === 0" class="text-sm text-neutral-600">
                No items on this order.
            </p>
            <ul v-else class="divide-y rounded border text-sm">
                <li
                    v-for="item in items"
                    :key="item.id"
                    class="flex items-start justify-between gap-4 px-4 py-3"
                >
                    <div>
                        <p class="font-medium">{{ item.description }}</p>
                        <p class="text-neutral-600">
                            <template v-if="item.sku">{{ item.sku }} · </template>
                            Qty {{ formatQuantity(item.quantity) }}
                            · {{ formatMoney(item.unit_price) }}/ea
                        </p>
                    </div>
                    <p class="font-medium">
                        {{ formatMoney(item.extended_price) }}
                    </p>
                </li>
            </ul>
        </section>

        <section class="space-y-3">
            <div>
                <h2 class="text-base font-semibold">Components</h2>
                <p class="text-sm text-neutral-600">
                    Reconciliation breakdown: product lines plus tax, delivery,
                    tip, and discount.
                    <template v-if="!order.components_balanced">
                        Sum {{ formatMoney(order.component_sum) }} vs bank total
                        {{ formatMoney(order.total) }}.
                    </template>
                </p>
            </div>
            <p v-if="components.length === 0" class="text-sm text-neutral-600">
                No components generated yet.
            </p>
            <ul v-else class="divide-y rounded border text-sm">
                <li
                    v-for="component in components"
                    :key="component.id"
                    class="flex items-start justify-between gap-4 px-4 py-3"
                >
                    <div>
                        <p class="font-medium">{{ component.description }}</p>
                        <p class="text-neutral-600">
                            {{ component.type }}
                            <template v-if="component.category">
                                · {{ component.category.name }}
                            </template>
                            · {{ allocationLabel(component) }}
                        </p>
                        <OrderComponentRefundForm
                            class="mt-1"
                            :order-id="order.id"
                            :component="component"
                        />
                    </div>
                    <p class="font-medium">
                        {{ formatMoney(component.amount) }}
                    </p>
                </li>
            </ul>
        </section>

        <section class="space-y-3 rounded border px-4 py-3">
            <div>
                <h2 class="text-base font-semibold">Remove this order</h2>
                <p class="text-sm text-neutral-600">
                    Use this when {{ merchant.name }} imported the order
                    incorrectly. Deleting it lets the scraper import the same
                    order number again.
                </p>
                <p
                    v-if="!order.components_balanced"
                    class="mt-2 text-sm text-amber-800"
                >
                    <template v-if="gapExplained">
                        You can also mark a refund or change the bank total
                        above instead of removing the order.
                    </template>
                    <template v-else>
                        Unbalanced components are a common sign the scrape
                        missed items or fees. Removing the order lets you
                        import it again.
                    </template>
                </p>
                <p
                    v-if="has_allocations"
                    class="mt-2 text-sm text-amber-800"
                >
                    This order is matched to bank transactions. Removing it will
                    undo those matches.
                </p>
            </div>
            <button
                type="button"
                class="btn rounded border px-4 text-sm text-neutral-700 hover:bg-neutral-100 disabled:opacity-50"
                :disabled="!can_delete || deleting"
                @click="removeOrder"
            >
                Remove imported order
            </button>
        </section>
    </div>
</template>
