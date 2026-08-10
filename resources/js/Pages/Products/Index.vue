<script setup>
    import AuthenticatedLayout from '../../Layouts/AuthenticatedLayout.vue';
    import { Link, router, usePage } from '@inertiajs/vue3';
    import { computed, reactive, ref } from 'vue';

    defineOptions({ layout: AuthenticatedLayout });

    let props = defineProps({
        products: {
            type: Array,
            required: true,
        },
        categories: {
            type: Array,
            required: true,
        },
    });

    let page = usePage();
    let flashSuccess = computed(() => page.props.flash?.success);
    let categoryForms = reactive({});
    let savingProductId = ref(null);
    let reconciling = ref(false);

    for (let product of props.products) {
        categoryForms[product.id] = '';
    }

    let runProductReconciliation = () => {
        reconciling.value = true;
        router.post(
            '/products/reconcile',
            {},
            {
                onFinish: () => {
                    reconciling.value = false;
                },
            },
        );
    };

    let saveCategory = (product) => {
        let categoryId = categoryForms[product.id];

        if (!categoryId) {
            return;
        }

        savingProductId.value = product.id;
        router.patch(
            `/products/${product.id}/category`,
            { category_id: categoryId },
            {
                onFinish: () => {
                    savingProductId.value = null;
                },
            },
        );
    };
</script>

<template>
    <div class="space-y-6">
        <div class="flex flex-wrap items-start justify-between gap-4">
            <div>
                <Link href="/categories" class="text-sm underline"
                    >Back to categories</Link
                >
                <h1 class="mt-2 text-2xl font-semibold">
                    Uncategorized products
                </h1>
                <p class="text-sm text-neutral-600">
                    Walmart and Sam&apos;s Club items matched into products.
                    Assign an expense category so future orders inherit it.
                </p>
            </div>
            <button
                type="button"
                class="rounded border px-4 py-2 text-sm"
                :disabled="reconciling"
                @click="runProductReconciliation"
            >
                {{
                    reconciling
                        ? 'Running…'
                        : 'Run product reconciliation'
                }}
            </button>
        </div>

        <p
            v-if="flashSuccess"
            class="rounded border border-green-200 bg-green-50 px-3 py-2 text-sm text-green-800"
        >
            {{ flashSuccess }}
        </p>

        <div v-if="products.length === 0" class="text-sm text-neutral-600">
            No uncategorized products. Import Walmart orders and run product
            reconciliation (or the full reconciliation pipeline) to create
            them. Amazon lines stay on order components and are categorized
            there.
        </div>

        <ul v-else class="divide-y rounded border">
            <li
                v-for="product in products"
                :key="product.id"
                class="space-y-3 px-4 py-3"
            >
                <div>
                    <p class="font-medium">{{ product.name }}</p>
                    <p class="text-sm text-neutral-600">
                        {{ product.merchant?.name ?? 'Unknown merchant' }}
                        <span v-if="product.sku"> · SKU {{ product.sku }}</span>
                        · {{ product.order_items_count }}
                        {{
                            product.order_items_count === 1
                                ? 'line item'
                                : 'line items'
                        }}
                    </p>
                </div>
                <div class="flex flex-wrap items-end gap-2">
                    <label class="block text-sm">
                        <span class="text-neutral-600">Category</span>
                        <select
                            v-model="categoryForms[product.id]"
                            class="mt-1 block rounded border px-3 py-1.5"
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
                        type="button"
                        class="rounded bg-neutral-900 px-3 py-1.5 text-sm text-white disabled:opacity-50"
                        :disabled="
                            !categoryForms[product.id] ||
                            savingProductId === product.id
                        "
                        @click="saveCategory(product)"
                    >
                        {{
                            savingProductId === product.id
                                ? 'Saving…'
                                : 'Save'
                        }}
                    </button>
                </div>
            </li>
        </ul>
    </div>
</template>
