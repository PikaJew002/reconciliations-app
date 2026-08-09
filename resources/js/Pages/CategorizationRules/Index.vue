<script setup>
    import AuthenticatedLayout from '../../Layouts/AuthenticatedLayout.vue';
    import { Link, router, usePage } from '@inertiajs/vue3';
    import { computed } from 'vue';

    defineOptions({ layout: AuthenticatedLayout });

    defineProps({
        rules: {
            type: Array,
            required: true,
        },
        matchModes: {
            type: Array,
            required: true,
        },
    });

    let page = usePage();
    let flashSuccess = computed(() => page.props.flash?.success);

    let matchModeLabel = (mode) => {
        return {
            exact_description_and_amount: 'Exact description + amount',
            amount_and_merchant: 'Amount + merchant',
            merchant: 'Merchant only',
            description: 'Description only',
        }[mode] ?? mode;
    };

    let toggleActive = (rule) => {
        router.patch(`/categorization-rules/${rule.id}`, {
            is_active: !rule.is_active,
        });
    };

    let deleteRule = (rule) => {
        if (!window.confirm('Delete this categorization rule?')) {
            return;
        }

        router.delete(`/categorization-rules/${rule.id}`);
    };
</script>

<template>
    <div class="space-y-6">
        <div>
            <Link href="/categories" class="text-sm underline"
                >Back to categories</Link
            >
            <h1 class="mt-2 text-2xl font-semibold">Categorization rules</h1>
            <p class="text-sm text-neutral-600">
                Learned rules that auto-apply when similar transactions appear.
            </p>
        </div>

        <p
            v-if="flashSuccess"
            class="rounded border border-green-200 bg-green-50 px-3 py-2 text-sm text-green-800"
        >
            {{ flashSuccess }}
        </p>

        <div v-if="rules.length === 0" class="text-sm text-neutral-600">
            No rules yet. Categorize a transaction on Reconciliation to create
            one.
        </div>

        <ul v-else class="divide-y rounded border">
            <li
                v-for="rule in rules"
                :key="rule.id"
                class="space-y-2 px-4 py-3"
            >
                <div class="flex flex-wrap items-start justify-between gap-3">
                    <div>
                        <p class="font-medium">
                            {{ rule.category?.name ?? 'Unknown category' }}
                            <span class="text-sm font-normal text-neutral-600">
                                · {{ rule.classification }}
                            </span>
                        </p>
                        <p class="text-sm text-neutral-600">
                            {{ matchModeLabel(rule.match_mode) }}
                        </p>
                        <p
                            v-if="rule.merchant"
                            class="text-sm text-neutral-600"
                        >
                            Merchant: {{ rule.merchant.name }}
                        </p>
                        <p
                            v-if="rule.normalized_pattern"
                            class="text-sm text-neutral-600"
                        >
                            Pattern: {{ rule.normalized_pattern }}
                        </p>
                        <p
                            v-if="rule.amount !== null"
                            class="text-sm text-neutral-600"
                        >
                            Amount: {{ rule.amount }}
                        </p>
                    </div>
                    <div class="flex flex-wrap gap-2 text-sm">
                        <button
                            type="button"
                            class="rounded border px-3 py-1.5"
                            @click="toggleActive(rule)"
                        >
                            {{ rule.is_active ? 'Disable' : 'Enable' }}
                        </button>
                        <button
                            type="button"
                            class="rounded border px-3 py-1.5 text-red-700"
                            @click="deleteRule(rule)"
                        >
                            Delete
                        </button>
                    </div>
                </div>
                <p
                    class="text-xs"
                    :class="
                        rule.is_active ? 'text-green-700' : 'text-neutral-500'
                    "
                >
                    {{ rule.is_active ? 'Active' : 'Disabled' }}
                </p>
            </li>
        </ul>
    </div>
</template>
