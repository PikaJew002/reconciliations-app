<script setup>
    import AuthenticatedLayout from '../../Layouts/AuthenticatedLayout.vue';
    import ColorField from '../../Components/ColorField.vue';
    import { stripColorHash } from '../../Composables/categoryColor.js';
    import { Link, useForm } from '@inertiajs/vue3';

    defineOptions({ layout: AuthenticatedLayout });

    let props = defineProps({
        category: {
            type: Object,
            required: true,
        },
        kinds: {
            type: Array,
            required: true,
        },
    });

    let form = useForm({
        name: props.category.name,
        kind: props.category.kind,
        color: props.category.color ?? '',
    });

    let kindLabel = (kind) => {
        return (
            {
                bill: 'Bill',
                expense: 'Expense',
                income: 'Income',
            }[kind] ?? kind
        );
    };

    let submit = () => {
        form
            .transform((data) => ({
                ...data,
                color: data.color ? `#${stripColorHash(data.color)}` : null,
            }))
            .patch(`/categories/${props.category.id}`);
    };
</script>

<template>
    <div class="space-y-6">
        <div>
            <Link href="/categories" class="text-sm underline"
                >Back to categories</Link
            >
            <h1 class="mt-2 text-2xl font-semibold">Edit category</h1>
            <p class="text-sm text-neutral-600">
                Update this category’s name, kind, or color.
            </p>
        </div>

        <form class="space-y-4" @submit.prevent="submit">
            <div>
                <label class="mb-1 block text-sm" for="name">Name</label>
                <input
                    id="name"
                    v-model="form.name"
                    type="text"
                    class="w-full rounded border px-3 py-2"
                    required
                />
                <p v-if="form.errors.name" class="mt-1 text-sm text-red-600">
                    {{ form.errors.name }}
                </p>
            </div>

            <div>
                <label class="mb-1 block text-sm" for="kind">Kind</label>
                <select
                    id="kind"
                    v-model="form.kind"
                    class="w-full rounded border px-3 py-2"
                    required
                >
                    <option v-for="kind in kinds" :key="kind" :value="kind">
                        {{ kindLabel(kind) }}
                    </option>
                </select>
                <p v-if="form.errors.kind" class="mt-1 text-sm text-red-600">
                    {{ form.errors.kind }}
                </p>
            </div>

            <div>
                <label class="mb-1 block text-sm" for="color"
                    >Color
                    <span class="text-neutral-500">(optional)</span></label
                >
                <ColorField id="color" v-model="form.color" />
                <p v-if="form.errors.color" class="mt-1 text-sm text-red-600">
                    {{ form.errors.color }}
                </p>
            </div>

            <div class="flex flex-wrap gap-2">
                <button
                    type="submit"
                    class="rounded bg-brand hover:bg-brand-hover px-4 py-2 text-white disabled:opacity-50"
                    :disabled="form.processing"
                >
                    Save changes
                </button>
                <Link
                    href="/categories"
                    class="rounded border px-4 py-2 text-neutral-700 hover:bg-neutral-100"
                >
                    Cancel
                </Link>
            </div>
        </form>
    </div>
</template>
