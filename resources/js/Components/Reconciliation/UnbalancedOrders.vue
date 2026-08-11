<script setup>
    import { formatMoney } from '../../Composables/useReconciliationFormatting.js';
    import { router } from '@inertiajs/vue3';
    import { computed, reactive, ref, watch } from 'vue';

    let props = defineProps({
        unbalancedOrders: {
            type: Array,
            required: true,
        },
        categories: {
            type: Array,
            default: () => [],
        },
    });

    let componentForms = reactive({});
    let quantityForms = reactive({});
    let componentCategoryForms = reactive({});
    let savingOrderId = ref(null);
    let savingQuantityKey = ref(null);
    let savingComponentCategoryKey = ref(null);

    let expenseCategories = computed(() =>
        props.categories.filter((category) => category.kind === 'expense'),
    );

    function syncComponentForms(orders) {
        for (let order of orders) {
            if (!componentForms[order.id]) {
                componentForms[order.id] = {
                    type: order.gap > 0 ? 'delivery' : 'other',
                    description:
                        order.gap > 0 ? 'Fast delivery fee' : 'Adjustment',
                    amount: Number(order.gap.toFixed(2)),
                };
            }

            for (let component of order.components) {
                if (componentCategoryForms[component.id] === undefined) {
                    componentCategoryForms[component.id] =
                        component.category_id ?? '';
                }

                if (
                    !component.can_edit_quantity ||
                    component.order_item_id == null ||
                    quantityForms[component.order_item_id] !== undefined
                ) {
                    continue;
                }

                quantityForms[component.order_item_id] = Number(
                    component.quantity,
                );
            }
        }
    }

    function addComponent(order) {
        let form = componentForms[order.id];

        if (!form) {
            return;
        }

        savingOrderId.value = order.id;

        router.post(`/reconciliation/orders/${order.id}/components`, form, {
            preserveScroll: true,
            onSuccess: () => {
                delete componentForms[order.id];
            },
            onFinish: () => {
                savingOrderId.value = null;
            },
        });
    }

    function updateItemQuantity(order, component) {
        if (!component.can_edit_quantity || component.order_item_id == null) {
            return;
        }

        let quantity = quantityForms[component.order_item_id];
        let key = `${order.id}-${component.order_item_id}`;

        savingQuantityKey.value = key;

        router.patch(
            `/reconciliation/orders/${order.id}/items/${component.order_item_id}`,
            { quantity },
            {
                preserveScroll: true,
                onFinish: () => {
                    savingQuantityKey.value = null;
                },
            },
        );
    }

    function deleteComponent(order, component) {
        if (!component.can_delete) {
            return;
        }

        router.delete(
            `/reconciliation/orders/${order.id}/components/${component.id}`,
            {
                preserveScroll: true,
            },
        );
    }

    function saveComponentCategory(order, component) {
        let categoryId = componentCategoryForms[component.id];

        if (!categoryId) {
            return;
        }

        let key = `${order.id}-${component.id}`;
        savingComponentCategoryKey.value = key;

        router.patch(
            `/reconciliation/orders/${order.id}/components/${component.id}/category`,
            { category_id: categoryId },
            {
                preserveScroll: true,
                onFinish: () => {
                    savingComponentCategoryKey.value = null;
                },
            },
        );
    }

    watch(
        () => props.unbalancedOrders,
        (orders) => syncComponentForms(orders),
        { immediate: true },
    );
</script>

<template>
    <div class="space-y-4">
        <div>
            <h2 class="text-base font-semibold">Unbalanced components</h2>
            <p class="text-sm text-neutral-600">
                Orders whose components do not add up to the order total. Fix an
                item quantity, add a missing fee (for example Fast delivery), or
                remove a bad component, then re-run reconciliation.
            </p>
        </div>

        <p
            v-if="unbalancedOrders.length === 0"
            class="text-sm text-neutral-600"
        >
            No unbalanced orders.
        </p>

        <ul v-else class="space-y-4">
            <li
                v-for="order in unbalancedOrders"
                :key="order.id"
                class="space-y-3 rounded border px-4 py-3 text-sm"
            >
                <div class="flex items-start justify-between gap-4">
                    <div>
                        <p class="font-medium">
                            {{ order.merchant || 'Unknown merchant' }}
                            <span class="font-normal text-neutral-600"
                                >#{{ order.order_number }}</span
                            >
                        </p>
                        <p class="text-neutral-600">
                            {{ order.ordered_at || 'No date' }}
                            <span v-if="order.payment_last_four">
                                · card {{ order.payment_last_four }}</span
                            >
                        </p>
                    </div>
                    <div class="text-right">
                        <p class="font-medium">
                            {{ formatMoney(order.total) }} total
                        </p>
                        <p class="text-neutral-600">
                            Components
                            {{ formatMoney(order.component_sum) }}
                        </p>
                        <p class="font-medium text-amber-800">
                            Gap {{ formatMoney(order.gap) }}
                        </p>
                    </div>
                </div>

                <ul class="divide-y rounded border">
                    <li
                        v-for="component in order.components"
                        :key="component.id"
                        class="flex flex-col gap-2 px-3 py-2 sm:flex-row sm:items-center sm:justify-between"
                    >
                        <div>
                            <p class="font-medium">
                                {{ component.description }}
                            </p>
                            <p class="text-neutral-600">
                                {{ component.type }}
                                <span v-if="component.unit_price != null">
                                    ·
                                    {{ formatMoney(component.unit_price) }}/ea</span
                                >
                                <span v-if="component.is_user_modified">
                                    · manual</span
                                >
                            </p>
                        </div>
                        <div class="flex flex-wrap items-center gap-3">
                            <form
                                v-if="component.can_edit_quantity"
                                class="flex items-center gap-2"
                                @submit.prevent="
                                    updateItemQuantity(order, component)
                                "
                            >
                                <label
                                    class="flex items-center gap-1.5 text-neutral-600"
                                >
                                    <span>Qty</span>
                                    <input
                                        v-model.number="
                                            quantityForms[
                                                component.order_item_id
                                            ]
                                        "
                                        type="number"
                                        min="0.001"
                                        step="any"
                                        class="w-20 rounded border px-2 py-1"
                                        required
                                    />
                                </label>
                                <button
                                    type="submit"
                                    class="text-xs text-neutral-800 underline disabled:opacity-50"
                                    :disabled="
                                        savingQuantityKey ===
                                        `${order.id}-${component.order_item_id}`
                                    "
                                >
                                    Update
                                </button>
                            </form>
                            <form
                                v-if="expenseCategories.length > 0"
                                class="flex items-center gap-2"
                                @submit.prevent="
                                    saveComponentCategory(order, component)
                                "
                            >
                                <select
                                    v-model="
                                        componentCategoryForms[component.id]
                                    "
                                    class="rounded border px-2 py-1 text-xs"
                                >
                                    <option disabled value="">Category</option>
                                    <option
                                        v-for="category in expenseCategories"
                                        :key="category.id"
                                        :value="category.id"
                                    >
                                        {{ category.name }}
                                    </option>
                                </select>
                                <button
                                    type="submit"
                                    class="text-xs text-neutral-800 underline disabled:opacity-50"
                                    :disabled="
                                        savingComponentCategoryKey ===
                                            `${order.id}-${component.id}` ||
                                        !componentCategoryForms[component.id]
                                    "
                                >
                                    Save
                                </button>
                            </form>
                            <p class="font-medium">
                                {{ formatMoney(component.amount) }}
                            </p>
                            <button
                                v-if="component.can_delete"
                                type="button"
                                class="text-xs text-red-700 underline"
                                @click="deleteComponent(order, component)"
                            >
                                Remove
                            </button>
                        </div>
                    </li>
                </ul>

                <form
                    v-if="componentForms[order.id]"
                    class="grid gap-3 sm:grid-cols-4"
                    @submit.prevent="addComponent(order)"
                >
                    <label class="block space-y-1 sm:col-span-1">
                        <span class="text-neutral-600">Type</span>
                        <select
                            v-model="componentForms[order.id].type"
                            class="w-full rounded border px-2 py-1.5"
                        >
                            <option value="delivery">Delivery</option>
                            <option value="fee">Fee</option>
                            <option value="tip">Tip</option>
                            <option value="tax">Tax</option>
                            <option value="other">Other</option>
                        </select>
                    </label>

                    <label class="block space-y-1 sm:col-span-2">
                        <span class="text-neutral-600">Description</span>
                        <input
                            v-model="componentForms[order.id].description"
                            type="text"
                            class="w-full rounded border px-2 py-1.5"
                            required
                        />
                    </label>

                    <label class="block space-y-1 sm:col-span-1">
                        <span class="text-neutral-600">Amount</span>
                        <input
                            v-model="componentForms[order.id].amount"
                            type="number"
                            step="0.01"
                            class="w-full rounded border px-2 py-1.5"
                            required
                        />
                    </label>

                    <div class="sm:col-span-4">
                        <button
                            type="submit"
                            class="rounded bg-neutral-900 px-3 py-1.5 text-white disabled:opacity-50"
                            :disabled="savingOrderId === order.id"
                        >
                            {{
                                savingOrderId === order.id
                                    ? 'Saving…'
                                    : 'Add component'
                            }}
                        </button>
                    </div>
                </form>
            </li>
        </ul>
    </div>
</template>
