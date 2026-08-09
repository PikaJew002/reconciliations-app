<script setup>
    import AuthenticatedLayout from '../../Layouts/AuthenticatedLayout.vue';
    import { Link, router, usePage } from '@inertiajs/vue3';
    import { computed } from 'vue';

    defineOptions({ layout: AuthenticatedLayout });

    let props = defineProps({
        categories: {
            type: Array,
            required: true,
        },
        filters: {
            type: Object,
            required: true,
        },
        kinds: {
            type: Array,
            required: true,
        },
    });

    let page = usePage();
    let flashSuccess = computed(() => page.props.flash?.success);
    let flashError = computed(() => page.props.flash?.error);

    let kindLabel = (kind) => {
        return kind === 'bill' ? 'Bill' : 'Expense';
    };

    let setKindFilter = (kind) => {
        router.get(
            '/categories',
            { kind: kind || undefined },
            {
                preserveState: true,
                replace: true,
            },
        );
    };

    let deleteCategory = (category) => {
        if (category.is_in_use) {
            return;
        }

        if (!window.confirm(`Delete category "${category.name}"?`)) {
            return;
        }

        router.delete(`/categories/${category.id}`);
    };
</script>

<template>
    <div class="space-y-6">
        <div class="flex flex-wrap items-start justify-between gap-4">
            <div>
                <h1 class="text-2xl font-semibold">Categories</h1>
                <p class="text-sm text-neutral-600">
                    Bill and expense categories used when classifying spend.
                </p>
            </div>
            <div class="flex flex-wrap gap-2">
                <Link
                    href="/categorization-rules"
                    class="rounded border px-4 py-2 text-sm"
                >
                    Rules
                </Link>
                <Link
                    href="/categories/create"
                    class="rounded bg-neutral-900 px-4 py-2 text-sm text-white"
                >
                    Add category
                </Link>
            </div>
        </div>

        <p
            v-if="flashSuccess"
            class="rounded border border-green-200 bg-green-50 px-3 py-2 text-sm text-green-800"
        >
            {{ flashSuccess }}
        </p>
        <p
            v-if="flashError"
            class="rounded border border-red-200 bg-red-50 px-3 py-2 text-sm text-red-800"
        >
            {{ flashError }}
        </p>

        <div class="flex flex-wrap gap-2 text-sm">
            <button
                type="button"
                class="rounded border px-3 py-1.5"
                :class="
                    !filters.kind
                        ? 'border-neutral-900 bg-neutral-900 text-white'
                        : ''
                "
                @click="setKindFilter(null)"
            >
                All
            </button>
            <button
                v-for="kind in kinds"
                :key="kind"
                type="button"
                class="rounded border px-3 py-1.5"
                :class="
                    filters.kind === kind
                        ? 'border-neutral-900 bg-neutral-900 text-white'
                        : ''
                "
                @click="setKindFilter(kind)"
            >
                {{ kindLabel(kind) }}
            </button>
        </div>

        <div v-if="categories.length === 0" class="text-sm text-neutral-600">
            No categories yet. Add one to start classifying bills and expenses.
        </div>

        <ul v-else class="divide-y rounded border">
            <li
                v-for="category in categories"
                :key="category.id"
                class="flex flex-wrap items-center justify-between gap-3 px-4 py-3"
            >
                <div class="flex items-center gap-3">
                    <span
                        class="inline-block h-5 w-5 shrink-0 rounded border border-neutral-300"
                        :class="category.color ? '' : 'border-dashed bg-neutral-50'"
                        :style="
                            category.color
                                ? { backgroundColor: category.color }
                                : undefined
                        "
                        :title="category.color || 'No color'"
                    />
                    <div>
                        <p class="font-medium">{{ category.name }}</p>
                        <p class="text-sm text-neutral-600">
                            {{ kindLabel(category.kind) }}
                        </p>
                    </div>
                </div>
                <div class="flex flex-wrap gap-2 text-sm">
                    <Link
                        :href="`/categories/${category.id}/edit`"
                        class="rounded border px-3 py-1.5"
                    >
                        Edit
                    </Link>
                    <button
                        type="button"
                        class="rounded border px-3 py-1.5 text-red-700 disabled:opacity-40"
                        :disabled="category.is_in_use"
                        :title="
                            category.is_in_use
                                ? 'Category is in use'
                                : 'Delete category'
                        "
                        @click="deleteCategory(category)"
                    >
                        Delete
                    </button>
                </div>
            </li>
        </ul>
    </div>
</template>
