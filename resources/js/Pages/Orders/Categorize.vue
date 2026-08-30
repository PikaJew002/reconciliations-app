<script setup>
    import AuthenticatedLayout from '../../Layouts/AuthenticatedLayout.vue';
    import { Link, router, usePage } from '@inertiajs/vue3';
    import { computed, reactive, ref, watch } from 'vue';

    defineOptions({ layout: AuthenticatedLayout });

    let props = defineProps({
        orders: {
            type: Array,
            required: true,
        },
        ordersTruncated: {
            type: Boolean,
            required: true,
        },
        categories: {
            type: Array,
            required: true,
        },
        filters: {
            type: Object,
            required: true,
        },
    });

    let page = usePage();
    let flashSuccess = computed(() => page.props.flash?.success);
    let search = ref(props.filters.q ?? '');
    let categorySelections = reactive({});
    let orderCategorySelections = reactive({});
    let savingKey = ref(null);
    let dismissedProductIds = ref(new Set());
    let dismissedItemIds = ref(new Set());
    let dismissedComponentIds = ref(new Set());

    watch(
        () => props.filters.q,
        (value) => {
            search.value = value ?? '';
        },
    );

    watch(
        () => props.orders,
        () => {
            dismissedProductIds.value = new Set();
            dismissedItemIds.value = new Set();
            dismissedComponentIds.value = new Set();
        },
    );

    let visibleOrders = computed(() => {
        return props.orders
            .map((order) => {
                let lines = order.lines.filter((line) => {
                    if (line.kind === 'component') {
                        return !dismissedComponentIds.value.has(line.id);
                    }

                    if (dismissedItemIds.value.has(line.id)) {
                        return false;
                    }

                    if (
                        line.product?.id &&
                        dismissedProductIds.value.has(line.product.id)
                    ) {
                        return false;
                    }

                    return true;
                });

                return { ...order, lines };
            })
            .filter((order) => order.lines.length > 0);
    });

    let selectionKey = (order, line) => `${order.id}:${line.kind}:${line.id}`;

    let ensureSelection = (key) => {
        if (categorySelections[key] === undefined) {
            categorySelections[key] = '';
        }
    };

    for (let order of props.orders) {
        if (orderCategorySelections[order.id] === undefined) {
            orderCategorySelections[order.id] = '';
        }

        for (let line of order.lines) {
            ensureSelection(selectionKey(order, line));
        }
    }

    watch(
        () => props.orders,
        (orders) => {
            for (let order of orders) {
                if (orderCategorySelections[order.id] === undefined) {
                    orderCategorySelections[order.id] = '';
                }

                for (let line of order.lines) {
                    ensureSelection(selectionKey(order, line));
                }
            }
        },
        { deep: true },
    );

    let submitSearch = () => {
        router.get(
            '/orders/categorize',
            { q: search.value || undefined },
            {
                preserveState: true,
                replace: true,
                only: ['orders', 'ordersTruncated', 'filters'],
            },
        );
    };

    let formatMoney = (amount) => {
        return new Intl.NumberFormat('en-US', {
            style: 'currency',
            currency: 'USD',
        }).format(amount);
    };

    let needsProductLines = (order) =>
        order.lines.filter((line) => line.status === 'needs_product');

    let needsCategoryLines = (order) =>
        order.lines.filter((line) => line.status === 'needs_category');

    let lineAmount = (line) => {
        if (line.kind === 'component') {
            return Number(line.amount) || 0;
        }

        return Number(line.extended_price) || 0;
    };

    let remainingTotal = (order) => {
        return order.lines.reduce(
            (sum, line) => sum + lineAmount(line),
            0,
        );
    };

    let reloadOptions = {
        preserveScroll: true,
        only: ['orders', 'ordersTruncated'],
    };

    let dismissProduct = (productId) => {
        let next = new Set(dismissedProductIds.value);
        next.add(productId);
        dismissedProductIds.value = next;
    };

    let dismissItem = (itemId) => {
        let next = new Set(dismissedItemIds.value);
        next.add(itemId);
        dismissedItemIds.value = next;
    };

    let dismissComponent = (componentId) => {
        let next = new Set(dismissedComponentIds.value);
        next.add(componentId);
        dismissedComponentIds.value = next;
    };

    let dismissOrder = (order) => {
        for (let line of order.lines) {
            if (line.kind === 'component') {
                dismissComponent(line.id);
            } else if (line.product?.id) {
                dismissProduct(line.product.id);
            } else {
                dismissItem(line.id);
            }
        }
    };

    let dismissOrderThisTime = (order) => {
        for (let line of order.lines) {
            if (line.kind === 'component') {
                dismissComponent(line.id);
            } else {
                dismissItem(line.id);
            }
        }
    };

    let orderSavingKey = (order) => `order:${order.id}`;

    let orderThisTimeSavingKey = (order) => `order-once:${order.id}`;

    let orderIsBusy = (order) => {
        return (
            savingKey.value === orderSavingKey(order) ||
            savingKey.value === orderThisTimeSavingKey(order)
        );
    };

    let submitOrderCategory = (order) => {
        let key = orderSavingKey(order);
        let categoryId = orderCategorySelections[order.id];

        if (!categoryId || savingKey.value) {
            return;
        }

        savingKey.value = key;
        dismissOrder(order);

        router.post(
            `/orders/${order.id}/categorize-all`,
            { category_id: Number(categoryId) },
            {
                ...reloadOptions,
                onFinish: () => {
                    savingKey.value = null;
                },
                onError: () => {
                    router.reload(reloadOptions);
                },
            },
        );
    };

    let submitOrderThisTimeOnly = (order) => {
        let key = orderThisTimeSavingKey(order);
        let categoryId = orderCategorySelections[order.id];

        if (!categoryId || savingKey.value) {
            return;
        }

        savingKey.value = key;
        dismissOrderThisTime(order);

        router.post(
            `/orders/${order.id}/categorize-all-this-time`,
            { category_id: Number(categoryId) },
            {
                ...reloadOptions,
                onFinish: () => {
                    savingKey.value = null;
                },
                onError: () => {
                    router.reload(reloadOptions);
                },
            },
        );
    };

    let submitProductCategory = (order, line) => {
        let key = selectionKey(order, line);
        let categoryId = categorySelections[key];

        if (!categoryId || !line.product?.id || savingKey.value) {
            return;
        }

        savingKey.value = key;
        dismissProduct(line.product.id);

        router.patch(
            `/products/${line.product.id}/category`,
            { category_id: Number(categoryId) },
            {
                ...reloadOptions,
                onFinish: () => {
                    savingKey.value = null;
                },
                onError: () => {
                    router.reload(reloadOptions);
                },
            },
        );
    };

    let submitCreateProduct = (order, line) => {
        let key = selectionKey(order, line);
        let categoryId = categorySelections[key];

        if (!categoryId || savingKey.value) {
            return;
        }

        savingKey.value = key;
        dismissItem(line.id);

        router.post(
            `/orders/items/${line.id}/categorize-as-product`,
            { category_id: Number(categoryId) },
            {
                ...reloadOptions,
                onFinish: () => {
                    savingKey.value = null;
                },
                onError: () => {
                    router.reload(reloadOptions);
                },
            },
        );
    };

    let instanceSavingKey = (order, line) =>
        `once:${selectionKey(order, line)}`;

    let lineIsBusy = (order, line) => {
        let key = selectionKey(order, line);

        return (
            savingKey.value === key ||
            savingKey.value === instanceSavingKey(order, line) ||
            savingKey.value === `remove:${key}`
        );
    };

    let submitThisTimeOnly = (order, line) => {
        let key = instanceSavingKey(order, line);
        let categoryId = categorySelections[selectionKey(order, line)];

        if (!categoryId || savingKey.value) {
            return;
        }

        savingKey.value = key;
        dismissItem(line.id);

        router.post(
            `/orders/items/${line.id}/categorize-this-time`,
            { category_id: Number(categoryId) },
            {
                ...reloadOptions,
                onFinish: () => {
                    savingKey.value = null;
                },
                onError: () => {
                    router.reload(reloadOptions);
                },
            },
        );
    };

    let submitComponentCategory = (order, line) => {
        let key = selectionKey(order, line);
        let categoryId = categorySelections[key];

        if (!categoryId || savingKey.value) {
            return;
        }

        savingKey.value = key;
        dismissComponent(line.id);

        router.patch(
            `/reconciliation/orders/${order.id}/components/${line.id}/category`,
            { category_id: Number(categoryId) },
            {
                ...reloadOptions,
                onFinish: () => {
                    savingKey.value = null;
                },
                onError: () => {
                    router.reload(reloadOptions);
                },
            },
        );
    };

    let removeItem = (order, line) => {
        let key = `remove:${selectionKey(order, line)}`;

        if (savingKey.value) {
            return;
        }

        let confirmed = window.confirm(
            'Remove this line from the order? Deletes the order item and its components. The product is deleted only if no other items use it. The order may become unbalanced until you fix it in Needs review.',
        );

        if (!confirmed) {
            return;
        }

        savingKey.value = key;
        dismissItem(line.id);

        router.delete(`/orders/items/${line.id}`, {
            ...reloadOptions,
            onFinish: () => {
                savingKey.value = null;
            },
            onError: () => {
                router.reload(reloadOptions);
            },
        });
    };

    let removeComponent = (order, line) => {
        let key = `remove:${selectionKey(order, line)}`;

        if (savingKey.value) {
            return;
        }

        let confirmed = window.confirm(
            'Remove this component from the order? The order may become unbalanced until you fix it in Needs review.',
        );

        if (!confirmed) {
            return;
        }

        savingKey.value = key;
        dismissComponent(line.id);

        router.delete(
            `/reconciliation/orders/${order.id}/components/${line.id}`,
            {
                ...reloadOptions,
                onFinish: () => {
                    savingKey.value = null;
                },
                onError: () => {
                    router.reload(reloadOptions);
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
                / Categorize
            </p>
            <h1 class="text-2xl font-semibold">Categorize order lines</h1>
            <p class="text-sm text-neutral-600">
                Walmart Save and Categorize remaining update the shared
                product. This time only categorizes leftover lines on this
                order and leaves later matches uncategorized. Amazon lines
                are always one-off.
            </p>
        </div>

        <p
            v-if="flashSuccess"
            class="rounded border border-green-200 bg-green-50 px-3 py-2 text-sm text-green-800"
        >
            {{ flashSuccess }}
        </p>

        <form class="flex flex-wrap gap-2" @submit.prevent="submitSearch">
            <input
                v-model="search"
                type="search"
                placeholder="Search order number or total"
                class="min-w-64 flex-1 rounded border px-3 text-sm"
            />
            <button type="submit" class="btn rounded border px-4 text-sm">
                Search
            </button>
        </form>

        <p
            v-if="ordersTruncated"
            class="rounded border border-amber-200 bg-amber-50 px-3 py-2 text-sm text-amber-900"
        >
            Showing the newest matching orders. Narrow with search if you need
            older ones.
        </p>

        <div
            v-if="visibleOrders.length === 0"
            class="text-sm text-neutral-600"
        >
            Nothing left to categorize. Import orders and run product
            reconciliation for Walmart when items still need products.
        </div>

        <ul v-else class="space-y-4">
            <li
                v-for="order in visibleOrders"
                :key="order.id"
                class="rounded border"
            >
                <div
                    class="flex flex-wrap items-start justify-between gap-3 border-b px-4 py-3"
                >
                    <div>
                        <p class="font-medium">
                            {{ order.merchant?.name ?? 'Order' }}
                            · #{{ order.order_number }}
                        </p>
                        <p class="text-sm text-neutral-600">
                            {{ order.ordered_at || '—' }}
                            · {{ order.status || '—' }}
                        </p>
                    </div>
                    <div class="flex flex-wrap items-end gap-3">
                        <p class="text-sm font-medium">
                            {{ formatMoney(remainingTotal(order)) }}
                            <span class="font-normal text-neutral-600">
                                uncategorized
                            </span>
                        </p>
                        <form
                            class="flex flex-wrap items-end gap-2"
                            @submit.prevent="submitOrderCategory(order)"
                        >
                            <label class="block text-sm">
                                <span class="text-neutral-600"
                                    >Remaining</span
                                >
                                <select
                                    v-model="orderCategorySelections[order.id]"
                                    class="mt-1 block rounded border px-3"
                                >
                                    <option value="">Select…</option>
                                    <option
                                        v-for="category in categories"
                                        :key="category.id"
                                        :value="category.id"
                                    >
                                        {{ category.name }}
                                    </option>
                                </select>
                            </label>
                            <button
                                type="submit"
                                class="btn rounded bg-brand hover:bg-brand-hover px-3 text-sm text-white disabled:opacity-50"
                                :disabled="
                                    !orderCategorySelections[order.id] ||
                                    orderIsBusy(order)
                                "
                            >
                                {{
                                    savingKey === orderSavingKey(order)
                                        ? 'Saving…'
                                        : 'Categorize remaining'
                                }}
                            </button>
                            <button
                                v-if="order.mode === 'items'"
                                type="button"
                                class="btn rounded border px-3 text-sm disabled:opacity-50"
                                :disabled="
                                    !orderCategorySelections[order.id] ||
                                    orderIsBusy(order)
                                "
                                @click="submitOrderThisTimeOnly(order)"
                            >
                                {{
                                    savingKey ===
                                    orderThisTimeSavingKey(order)
                                        ? 'Saving…'
                                        : 'This time only'
                                }}
                            </button>
                        </form>
                    </div>
                </div>

                <div class="space-y-4 px-4 py-3">
                    <template v-if="order.mode === 'items'">
                        <div
                            v-if="needsProductLines(order).length > 0"
                            class="space-y-3"
                        >
                            <p class="text-xs font-medium uppercase tracking-wide text-neutral-500">
                                Needs product
                            </p>
                            <ul class="space-y-3 border-l-2 border-neutral-200 pl-4">
                                <li
                                    v-for="line in needsProductLines(order)"
                                    :key="`item-${line.id}`"
                                    class="space-y-2"
                                >
                                    <div>
                                        <p class="text-sm font-medium">
                                            {{ line.description }}
                                        </p>
                                        <p class="text-xs text-neutral-600">
                                            <span v-if="line.sku"
                                                >SKU {{ line.sku }} ·
                                            </span>
                                            qty {{ line.quantity }} ·
                                            {{ formatMoney(line.extended_price) }}
                                        </p>
                                    </div>
                                    <form
                                        class="flex flex-wrap items-end gap-2"
                                        @submit.prevent="
                                            submitCreateProduct(order, line)
                                        "
                                    >
                                        <label class="block text-sm">
                                            <span class="text-neutral-600"
                                                >Category</span
                                            >
                                            <select
                                                v-model="
                                                    categorySelections[
                                                        selectionKey(
                                                            order,
                                                            line,
                                                        )
                                                    ]
                                                "
                                                class="mt-1 block rounded border px-3"
                                            >
                                                <option value="">
                                                    Select…
                                                </option>
                                                <option
                                                    v-for="category in categories"
                                                    :key="category.id"
                                                    :value="category.id"
                                                >
                                                    {{ category.name }}
                                                </option>
                                            </select>
                                        </label>
                                        <button
                                            type="submit"
                                            class="btn rounded bg-brand hover:bg-brand-hover px-3 text-sm text-white disabled:opacity-50"
                                            :disabled="
                                                !categorySelections[
                                                    selectionKey(order, line)
                                                ] || lineIsBusy(order, line)
                                            "
                                        >
                                            {{
                                                savingKey ===
                                                selectionKey(order, line)
                                                    ? 'Saving…'
                                                    : 'Create & save'
                                            }}
                                        </button>
                                        <button
                                            type="button"
                                            class="btn rounded border px-3 text-sm disabled:opacity-50"
                                            :disabled="
                                                !categorySelections[
                                                    selectionKey(order, line)
                                                ] || lineIsBusy(order, line)
                                            "
                                            @click="
                                                submitThisTimeOnly(order, line)
                                            "
                                        >
                                            {{
                                                savingKey ===
                                                instanceSavingKey(order, line)
                                                    ? 'Saving…'
                                                    : 'This time only'
                                            }}
                                        </button>
                                        <button
                                            type="button"
                                            class="btn rounded border px-3 text-sm text-red-700 disabled:opacity-50"
                                            :disabled="lineIsBusy(order, line)"
                                            @click="removeItem(order, line)"
                                        >
                                            {{
                                                savingKey ===
                                                `remove:${selectionKey(order, line)}`
                                                    ? 'Removing…'
                                                    : 'Remove'
                                            }}
                                        </button>
                                    </form>
                                </li>
                            </ul>
                        </div>

                        <div
                            v-if="needsCategoryLines(order).length > 0"
                            class="space-y-3"
                        >
                            <p class="text-xs font-medium uppercase tracking-wide text-neutral-500">
                                Product needs category
                            </p>
                            <ul class="space-y-3 border-l-2 border-neutral-200 pl-4">
                                <li
                                    v-for="line in needsCategoryLines(order)"
                                    :key="`item-${line.id}`"
                                    class="space-y-2"
                                >
                                    <div>
                                        <p class="text-sm font-medium">
                                            {{ line.description }}
                                        </p>
                                        <p class="text-xs text-neutral-600">
                                            <span v-if="line.product?.name">
                                                Product:
                                                {{ line.product.name }}
                                            </span>
                                            <span v-if="line.sku">
                                                · SKU {{ line.sku }}
                                            </span>
                                            · qty {{ line.quantity }} ·
                                            {{ formatMoney(line.extended_price) }}
                                        </p>
                                    </div>
                                    <form
                                        class="flex flex-wrap items-end gap-2"
                                        @submit.prevent="
                                            submitProductCategory(order, line)
                                        "
                                    >
                                        <label class="block text-sm">
                                            <span class="text-neutral-600"
                                                >Category</span
                                            >
                                            <select
                                                v-model="
                                                    categorySelections[
                                                        selectionKey(
                                                            order,
                                                            line,
                                                        )
                                                    ]
                                                "
                                                class="mt-1 block rounded border px-3"
                                            >
                                                <option value="">
                                                    Select…
                                                </option>
                                                <option
                                                    v-for="category in categories"
                                                    :key="category.id"
                                                    :value="category.id"
                                                >
                                                    {{ category.name }}
                                                </option>
                                            </select>
                                        </label>
                                        <button
                                            type="submit"
                                            class="btn rounded bg-brand hover:bg-brand-hover px-3 text-sm text-white disabled:opacity-50"
                                            :disabled="
                                                !categorySelections[
                                                    selectionKey(order, line)
                                                ] || lineIsBusy(order, line)
                                            "
                                        >
                                            {{
                                                savingKey ===
                                                selectionKey(order, line)
                                                    ? 'Saving…'
                                                    : 'Save'
                                            }}
                                        </button>
                                        <button
                                            type="button"
                                            class="btn rounded border px-3 text-sm disabled:opacity-50"
                                            :disabled="
                                                !categorySelections[
                                                    selectionKey(order, line)
                                                ] || lineIsBusy(order, line)
                                            "
                                            @click="
                                                submitThisTimeOnly(order, line)
                                            "
                                        >
                                            {{
                                                savingKey ===
                                                instanceSavingKey(order, line)
                                                    ? 'Saving…'
                                                    : 'This time only'
                                            }}
                                        </button>
                                        <button
                                            type="button"
                                            class="btn rounded border px-3 text-sm text-red-700 disabled:opacity-50"
                                            :disabled="lineIsBusy(order, line)"
                                            @click="removeItem(order, line)"
                                        >
                                            {{
                                                savingKey ===
                                                `remove:${selectionKey(order, line)}`
                                                    ? 'Removing…'
                                                    : 'Remove'
                                            }}
                                        </button>
                                    </form>
                                </li>
                            </ul>
                        </div>
                    </template>

                    <ul
                        v-else
                        class="space-y-3 border-l-2 border-neutral-200 pl-4"
                    >
                        <li
                            v-for="line in order.lines"
                            :key="`component-${line.id}`"
                            class="space-y-2"
                        >
                            <div>
                                <p class="text-sm font-medium">
                                    {{ line.description }}
                                </p>
                                <p class="text-xs text-neutral-600">
                                    {{ formatMoney(line.amount) }} · one-off
                                    category
                                </p>
                            </div>
                            <form
                                class="flex flex-wrap items-end gap-2"
                                @submit.prevent="
                                    submitComponentCategory(order, line)
                                "
                            >
                                <label class="block text-sm">
                                    <span class="text-neutral-600"
                                        >Category</span
                                    >
                                    <select
                                        v-model="
                                            categorySelections[
                                                selectionKey(order, line)
                                            ]
                                        "
                                        class="mt-1 block rounded border px-3"
                                    >
                                        <option value="">Select…</option>
                                        <option
                                            v-for="category in categories"
                                            :key="category.id"
                                            :value="category.id"
                                        >
                                            {{ category.name }}
                                        </option>
                                    </select>
                                </label>
                                <button
                                    type="submit"
                                    class="btn rounded bg-brand hover:bg-brand-hover px-3 text-sm text-white disabled:opacity-50"
                                    :disabled="
                                        !categorySelections[
                                            selectionKey(order, line)
                                        ] ||
                                        savingKey ===
                                            selectionKey(order, line)
                                    "
                                >
                                    {{
                                        savingKey ===
                                        selectionKey(order, line)
                                            ? 'Saving…'
                                            : 'Save'
                                    }}
                                </button>
                                <button
                                    type="button"
                                    class="btn rounded border px-3 text-sm text-red-700 disabled:opacity-50"
                                    :disabled="
                                        savingKey ===
                                            selectionKey(order, line) ||
                                        savingKey ===
                                            `remove:${selectionKey(order, line)}`
                                    "
                                    @click="removeComponent(order, line)"
                                >
                                    {{
                                        savingKey ===
                                        `remove:${selectionKey(order, line)}`
                                            ? 'Removing…'
                                            : 'Remove'
                                    }}
                                </button>
                            </form>
                        </li>
                    </ul>
                </div>
            </li>
        </ul>
    </div>
</template>
