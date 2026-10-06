<script setup>
    import OrderBankTotalForm from '../../Components/Reconciliation/OrderBankTotalForm.vue';
    import OrderComponentRefundForm from '../../Components/Reconciliation/OrderComponentRefundForm.vue';
    import AuthenticatedLayout from '../../Layouts/AuthenticatedLayout.vue';
    import { formatMoney } from '../../Composables/useReconciliationFormatting.js';
    import { Link, router, usePage } from '@inertiajs/vue3';
    import { computed, reactive, ref, watch } from 'vue';

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
        component_rows: {
            type: Array,
            required: true,
        },
        categories: {
            type: Array,
            default: () => [],
        },
        can_delete: {
            type: Boolean,
            required: true,
        },
        has_allocations: {
            type: Boolean,
            required: true,
        },
        tax_reconciliation: {
            type: Object,
            default: null,
        },
    });

    let page = usePage();

    let flashSuccess = computed(() => page.props.flash?.success);
    let deleting = ref(false);
    let closingAsGiftCard = ref(false);
    let componentForm = ref(null);
    let quantityForms = reactive({});
    let componentCategoryForms = reactive({});
    let savingComponent = ref(false);
    let savingQuantityKey = ref(null);
    let savingComponentCategoryKey = ref(null);

    let expenseCategories = computed(() =>
        props.categories.filter((category) => category.kind === 'expense'),
    );

    function defaultComponentForm() {
        let gap = Number(props.order.gap);

        return {
            type: gap > 0 ? 'delivery' : 'other',
            description: gap > 0 ? 'Fast delivery fee' : 'Adjustment',
            amount: Number(gap.toFixed(2)),
        };
    }

    function syncComponentForms() {
        if (props.order.can_edit && componentForm.value === null) {
            componentForm.value = defaultComponentForm();
        }

        for (let component of props.components) {
            if (componentCategoryForms[component.id] === undefined) {
                componentCategoryForms[component.id] = component.category_id ?? '';
            }

            if (
                !component.can_edit_quantity ||
                component.order_item_id == null ||
                quantityForms[component.order_item_id] !== undefined
            ) {
                continue;
            }

            quantityForms[component.order_item_id] = Number(component.quantity);
        }

        for (let row of props.component_rows) {
            if (
                !row.can_edit_quantity ||
                row.order_item_id == null ||
                quantityForms[row.order_item_id] !== undefined
            ) {
                continue;
            }

            quantityForms[row.order_item_id] = Number(row.quantity);
        }
    }

    function addComponent() {
        if (!props.order.can_edit || !componentForm.value || savingComponent.value) {
            return;
        }

        savingComponent.value = true;

        router.post(
            `/reconciliation/orders/${props.order.id}/components`,
            componentForm.value,
            {
                preserveScroll: true,
                onSuccess: () => {
                    componentForm.value = defaultComponentForm();
                },
                onFinish: () => {
                    savingComponent.value = false;
                },
            },
        );
    }

    function updateItemQuantity(component) {
        if (!component.can_edit_quantity || component.order_item_id == null) {
            return;
        }

        let quantity = quantityForms[component.order_item_id];

        savingQuantityKey.value = component.order_item_id;

        router.patch(
            `/reconciliation/orders/${props.order.id}/items/${component.order_item_id}`,
            { quantity },
            {
                preserveScroll: true,
                onFinish: () => {
                    savingQuantityKey.value = null;
                },
            },
        );
    }

    function closeAsGiftCard() {
        if (!props.order.can_close_as_gift_card || closingAsGiftCard.value) {
            return;
        }

        closingAsGiftCard.value = true;

        router.post(
            `/reconciliation/orders/${props.order.id}/close-as-gift-card`,
            {},
            {
                preserveScroll: true,
                onFinish: () => {
                    closingAsGiftCard.value = false;
                },
            },
        );
    }

    function deleteComponent(row) {
        if (!row.can_delete || row.component_ids.length === 0) {
            return;
        }

        let options = { preserveScroll: true };

        if (row.component_ids.length > 1) {
            options.data = { component_ids: row.component_ids };
        }

        router.delete(
            `/reconciliation/orders/${props.order.id}/components/${row.component_ids[0]}`,
            options,
        );
    }

    function saveComponentCategory(row) {
        let primaryId = row.component_ids[0];
        let categoryId = componentCategoryForms[primaryId];

        if (!primaryId || !categoryId) {
            return;
        }

        savingComponentCategoryKey.value = primaryId;

        let payload = { category_id: categoryId };

        if (row.component_ids.length > 1) {
            payload.component_ids = row.component_ids;
        }

        router.patch(
            `/reconciliation/orders/${props.order.id}/components/${primaryId}/category`,
            payload,
            {
                preserveScroll: true,
                onFinish: () => {
                    savingComponentCategoryKey.value = null;
                },
            },
        );
    }

    function refundTarget(row) {
        if (row.component_ids.length === 1) {
            return (
                props.components.find(
                    (component) => component.id === row.component_ids[0],
                ) ?? row
            );
        }

        return {
            id: row.component_ids[0],
            amount: row.amount,
            refund_amount: row.refund_amount,
            refund_kind: row.refund_kind,
            can_refund: row.can_refund,
        };
    }

    watch(
        () => [props.order, props.components, props.component_rows],
        () => syncComponentForms(),
        { immediate: true },
    );

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

    let taxRate = ref('0.06000');
    let taxSelected = reactive({});
    let savingTax = ref(false);

    function scaleTaxRate(rate) {
        let text = String(rate ?? '').trim();

        if (!/^\d+(\.\d+)?$/.test(text)) {
            return null;
        }

        let [whole, fraction = ''] = text.split('.');
        let fraction5 = (fraction + '00000').slice(0, 5);

        return Number(whole) * 100000 + Number(fraction5);
    }

    function unitTaxCents(unitCents, rateScaled) {
        if (unitCents < 0) {
            return -unitTaxCents(-unitCents, rateScaled);
        }

        return Math.floor((unitCents * rateScaled + 50000) / 100000);
    }

    function lineTaxCents(line) {
        if (taxRateScaled.value == null) {
            return null;
        }

        let negative = line.unit_cents < 0;
        let unit = unitTaxCents(
            Math.abs(line.unit_cents),
            taxRateScaled.value,
        );
        let cents = Math.floor(
            (unit * line.quantity_thousandths + 500) / 1000,
        );

        return negative ? -cents : cents;
    }

    watch(
        () => props.tax_reconciliation,
        (tax) => {
            if (tax == null) {
                return;
            }

            taxRate.value = tax.rate;

            for (let key of Object.keys(taxSelected)) {
                delete taxSelected[key];
            }

            for (let line of tax.lines) {
                taxSelected[line.id] = line.selected;
            }
        },
        { immediate: true },
    );

    let taxRateScaled = computed(() => scaleTaxRate(taxRate.value));

    let taxLines = computed(() => {
        if (props.tax_reconciliation == null) {
            return [];
        }

        return props.tax_reconciliation.lines.map((line) => {
            let taxCents = lineTaxCents(line);

            return {
                ...line,
                checked: Boolean(taxSelected[line.id]),
                tax_cents: taxCents,
                line_tax: taxCents == null ? '' : formatMoney(taxCents / 100),
            };
        });
    });

    function quantityForComponents(ids) {
        let seenItems = new Set();
        let total = 0;
        let any = false;

        for (let component of props.components) {
            if (!ids.includes(component.id) || component.quantity == null) {
                continue;
            }

            if (component.order_item_id != null) {
                if (seenItems.has(component.order_item_id)) {
                    continue;
                }

                seenItems.add(component.order_item_id);
            }

            total += Number(component.quantity);
            any = true;
        }

        return any ? total : null;
    }

    let taxGroups = computed(() => {
        let lines = taxLines.value;
        let lineById = new Map(lines.map((line) => [line.id, line]));
        let claimed = new Set();
        let groups = [];

        function pushGroup(members) {
            if (members.length === 0) {
                return;
            }

            let ids = members.map((line) => line.id);
            let priceCents = members.reduce(
                (sum, line) => sum + line.price_cents,
                0,
            );
            let taxCents = members.every((line) => line.tax_cents == null)
                ? null
                : members.reduce((sum, line) => sum + (line.tax_cents ?? 0), 0);

            groups.push({
                key: ids.join('-'),
                ids,
                description: members[0].description,
                type: members[0].type,
                tax_status: members[0].tax_status,
                quantity: quantityForComponents(ids),
                price_cents: priceCents,
                line_tax: taxCents == null ? '' : formatMoney(taxCents / 100),
            });
        }

        for (let row of props.component_rows) {
            let members = row.component_ids
                .map((id) => lineById.get(id))
                .filter((line) => line != null);

            if (members.length === 0) {
                continue;
            }

            let byStatus = new Map();

            for (let member of members) {
                claimed.add(member.id);
                let statusKey = String(member.tax_status);

                if (!byStatus.has(statusKey)) {
                    byStatus.set(statusKey, []);
                }

                byStatus.get(statusKey).push(member);
            }

            for (let groupMembers of byStatus.values()) {
                pushGroup(groupMembers);
            }
        }

        for (let line of lines) {
            if (!claimed.has(line.id)) {
                pushGroup([line]);
            }
        }

        return groups;
    });

    function taxGroupChecked(group) {
        return group.ids.every((id) => taxSelected[id]);
    }

    function setTaxGroup(group, checked) {
        for (let id of group.ids) {
            taxSelected[id] = checked;
        }
    }

    let roundedTaxCents = computed(() => {
        if (taxRateScaled.value == null) {
            return null;
        }

        return taxLines.value
            .filter((line) => line.checked)
            .reduce((sum, line) => sum + line.tax_cents, 0);
    });

    let taxMatches = computed(
        () =>
            roundedTaxCents.value != null &&
            props.tax_reconciliation != null &&
            roundedTaxCents.value === props.tax_reconciliation.tax_cents,
    );

    let taxGap = computed(() => {
        if (
            roundedTaxCents.value == null ||
            props.tax_reconciliation == null
        ) {
            return null;
        }

        return (
            (roundedTaxCents.value - props.tax_reconciliation.tax_cents) / 100
        );
    });

    let taxError = computed(() => {
        let error = page.props.errors?.component_ids;

        if (Array.isArray(error)) {
            return error[0] ?? '';
        }

        return error ?? '';
    });

    function saveTaxReconciliation() {
        if (
            !taxMatches.value ||
            savingTax.value ||
            props.tax_reconciliation == null
        ) {
            return;
        }

        savingTax.value = true;

        router.post(
            `/reconciliation/orders/${props.order.id}/tax-reconciliation`,
            {
                rate: taxRate.value,
                component_ids: taxLines.value
                    .filter((line) => line.checked)
                    .map((line) => line.id),
            },
            {
                preserveScroll: true,
                onFinish: () => {
                    savingTax.value = false;
                },
            },
        );
    }
</script>

<template>
    <div class="space-y-6">
        <p
            v-if="flashSuccess"
            class="rounded border border-green-200 bg-green-50 px-3 py-2 text-sm text-green-800"
        >
            {{ flashSuccess }}
        </p>
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
            <p
                v-if="order.in_vacation_window"
                class="mt-3 rounded border border-amber-200 bg-amber-50 px-3 py-2 text-sm text-amber-900"
            >
                This order is in a vacation window. Product lines stay
                uncategorized until you set them here. That does not change
                the product for later orders.
            </p>
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
                Fix a quantity, add a missing fee, or mark a refund in the
                components list. You can also remove the order below to
                re-import it.
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
            <button
                v-if="order.can_close_as_gift_card"
                type="button"
                class="text-sm text-neutral-800 underline disabled:opacity-50"
                :disabled="closingAsGiftCard"
                @click="closeAsGiftCard"
            >
                {{ closingAsGiftCard ? 'Closing…' : 'Close as gift card' }}
            </button>
        </section>

        <section
            v-if="order.allocated_transactions.length > 0"
            class="space-y-3"
        >
            <div>
                <h2 class="text-base font-semibold">Linked charges</h2>
                <p class="text-sm text-neutral-600">
                    Bank transactions allocated to this order.
                </p>
            </div>
            <ul class="divide-y rounded border text-sm">
                <li
                    v-for="transaction in order.allocated_transactions"
                    :key="transaction.id"
                    class="flex items-start justify-between gap-4 px-4 py-3"
                >
                    <div>
                        <p class="font-medium">{{ transaction.description }}</p>
                        <p class="text-neutral-600">
                            {{ transaction.posted_at || 'No date' }}
                            · {{ transaction.status }}
                            <template v-if="transaction.tender_label">
                                · {{ transaction.tender_label }}
                            </template>
                        </p>
                    </div>
                    <p class="font-medium">
                        {{ formatMoney(transaction.amount) }}
                    </p>
                </li>
            </ul>
        </section>

        <section v-if="tax_reconciliation" class="space-y-3">
            <div>
                <h2 class="text-base font-semibold">Sales tax</h2>
                <p class="text-sm text-neutral-600">
                    Uncheck lines that were not taxed. Repeated items show once
                    with a quantity and count together. Each unit is rounded to
                    the cent, then multiplied by its quantity. Save when that
                    total matches the order tax.
                </p>
            </div>
            <label class="flex items-center gap-2 text-sm">
                <span class="text-neutral-600">Rate</span>
                <input
                    v-model="taxRate"
                    type="text"
                    inputmode="decimal"
                    class="w-32 rounded border px-2"
                />
            </label>
            <ul class="divide-y rounded border text-sm">
                <li
                    v-for="group in taxGroups"
                    :key="group.key"
                    class="flex items-start justify-between gap-4 px-4 py-3"
                >
                    <label class="flex items-start gap-3">
                        <input
                            type="checkbox"
                            class="mt-1"
                            :checked="taxGroupChecked(group)"
                            @change="setTaxGroup(group, $event.target.checked)"
                        />
                        <span>
                            <span class="font-medium">{{ group.description }}</span>
                            <span class="block text-neutral-600">
                                {{ group.type }}
                                <template v-if="group.quantity != null">
                                    · Qty {{ formatQuantity(group.quantity) }}
                                </template>
                                <template v-if="group.tax_status === false">
                                    · learned exempt
                                </template>
                                <template v-else-if="group.tax_status === true">
                                    · learned taxable
                                </template>
                            </span>
                        </span>
                    </label>
                    <span class="text-right">
                        <span class="block font-medium">
                            {{ formatMoney(group.price_cents / 100) }}
                        </span>
                        <span class="text-neutral-600">{{ group.line_tax }}</span>
                    </span>
                </li>
            </ul>
            <div class="space-y-1 text-sm">
                <p>
                    Tax
                    <span class="font-medium">{{
                        formatMoney((roundedTaxCents ?? 0) / 100)
                    }}</span>
                    · Order tax
                    <span class="font-medium">{{
                        formatMoney(tax_reconciliation.tax_cents / 100)
                    }}</span>
                </p>
                <p :class="taxMatches ? 'text-green-800' : 'text-neutral-600'">
                    <template v-if="taxMatches">
                        Tax total matches the order tax.
                    </template>
                    <template v-else-if="taxGap != null">
                        Off by {{ formatMoney(taxGap) }}.
                    </template>
                </p>
                <p v-if="taxError" class="text-red-700">{{ taxError }}</p>
            </div>
            <button
                type="button"
                class="btn rounded bg-brand px-3 text-white hover:bg-brand-hover disabled:opacity-50"
                :disabled="!taxMatches || savingTax"
                @click="saveTaxReconciliation"
            >
                {{ savingTax ? 'Saving…' : 'Save sales tax' }}
            </button>
        </section>

        <section class="space-y-3">
            <div>
                <h2 class="text-base font-semibold">Components</h2>
                <p class="text-sm text-neutral-600">
                    Imported product lines, plus tax, delivery, tip, and
                    discount. The same item shows once with its quantity. Edit
                    a quantity, assign a category, mark a refund, or add a
                    missing fee here.
                    <template v-if="!order.components_balanced">
                        Sum {{ formatMoney(order.component_sum) }} vs bank total
                        {{ formatMoney(order.total) }}.
                    </template>
                </p>
            </div>
            <p
                v-if="component_rows.length === 0"
                class="text-sm text-neutral-600"
            >
                No components generated yet.
            </p>
            <ul v-else class="divide-y rounded border text-sm">
                <li
                    v-for="row in component_rows"
                    :key="row.key"
                    class="flex flex-col gap-2 px-4 py-3 sm:flex-row sm:items-start sm:justify-between"
                >
                    <div>
                        <p class="font-medium">{{ row.description }}</p>
                        <p class="text-neutral-600">
                            {{ row.type }}
                            ·
                            {{ row.category?.name || 'Uncategorized' }}
                            <template v-if="row.sku"> · {{ row.sku }}</template>
                            <template
                                v-if="
                                    row.quantity != null &&
                                    !row.can_edit_quantity
                                "
                            >
                                · Qty {{ formatQuantity(row.quantity) }}
                            </template>
                            <template v-if="row.unit_price != null">
                                · {{ formatMoney(row.unit_price) }}/ea
                            </template>
                            <template v-if="row.component_ids.length === 0">
                                · not included in the component total
                            </template>
                            <template v-if="row.is_user_modified">
                                · manual
                            </template>
                            <template v-if="row.component_ids.length > 0">
                                · {{ allocationLabel(row) }}
                            </template>
                        </p>
                    </div>
                    <div class="flex flex-wrap items-center gap-3">
                        <form
                            v-if="row.can_edit_quantity"
                            class="flex items-center gap-2"
                            @submit.prevent="updateItemQuantity(row)"
                        >
                            <label
                                class="flex items-center gap-1.5 text-neutral-600"
                            >
                                <span>Qty</span>
                                <input
                                    v-model.number="
                                        quantityForms[row.order_item_id]
                                    "
                                    type="number"
                                    min="0.001"
                                    step="any"
                                    class="w-20 rounded border px-2"
                                    required
                                />
                            </label>
                            <button
                                type="submit"
                                class="text-xs text-neutral-800 underline disabled:opacity-50"
                                :disabled="
                                    savingQuantityKey === row.order_item_id
                                "
                            >
                                Update
                            </button>
                        </form>
                        <form
                            v-if="
                                expenseCategories.length > 0 &&
                                row.component_ids.length > 0
                            "
                            class="flex items-center gap-2"
                            @submit.prevent="saveComponentCategory(row)"
                        >
                            <select
                                v-model="
                                    componentCategoryForms[row.component_ids[0]]
                                "
                                class="rounded border px-2 text-xs"
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
                                        row.component_ids[0] ||
                                    !componentCategoryForms[
                                        row.component_ids[0]
                                    ]
                                "
                            >
                                Save
                            </button>
                        </form>
                        <OrderComponentRefundForm
                            v-if="row.component_ids.length > 0"
                            :order-id="order.id"
                            :component="refundTarget(row)"
                            :component-ids="
                                row.component_ids.length > 1
                                    ? row.component_ids
                                    : []
                            "
                        />
                        <p class="font-medium">
                            {{ formatMoney(row.amount) }}
                        </p>
                        <button
                            v-if="row.can_delete"
                            type="button"
                            class="text-xs text-red-700 underline"
                            @click="deleteComponent(row)"
                        >
                            Remove
                        </button>
                    </div>
                </li>
            </ul>
            <form
                v-if="order.can_edit && componentForm"
                class="grid gap-3 sm:grid-cols-4"
                @submit.prevent="addComponent"
            >
                <label class="block space-y-1 sm:col-span-1">
                    <span class="text-neutral-600">Type</span>
                    <select
                        v-model="componentForm.type"
                        class="w-full rounded border px-2"
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
                        v-model="componentForm.description"
                        type="text"
                        class="w-full rounded border px-2"
                        required
                    />
                </label>
                <label class="block space-y-1 sm:col-span-1">
                    <span class="text-neutral-600">Amount</span>
                    <input
                        v-model="componentForm.amount"
                        type="number"
                        step="0.01"
                        class="w-full rounded border px-2"
                        required
                    />
                </label>
                <div class="sm:col-span-4">
                    <button
                        type="submit"
                        class="btn rounded bg-brand px-3 text-white hover:bg-brand-hover disabled:opacity-50"
                        :disabled="savingComponent"
                    >
                        {{ savingComponent ? 'Saving…' : 'Add component' }}
                    </button>
                </div>
            </form>
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
                        You can also mark a refund, change the bank total, or
                        fix the components above instead of removing the order.
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
