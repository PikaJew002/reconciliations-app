<script setup>
    import AuthenticatedLayout from '../../Layouts/AuthenticatedLayout.vue';
    import { Link, useForm, usePage } from '@inertiajs/vue3';
    import { computed, watch } from 'vue';

    defineOptions({ layout: AuthenticatedLayout });

    let props = defineProps({
        year: {
            type: Number,
            required: true,
        },
        total_monthly: {
            type: Number,
            required: true,
        },
        total_annual: {
            type: Number,
            required: true,
        },
        categories: {
            type: Array,
            required: true,
        },
    });

    let page = usePage();
    let flashSuccess = computed(() => page.props.flash?.success);

    let form = useForm({
        limits: props.categories.map((category) => ({
            category_id: category.id,
            amount:
                category.monthly_budget === null ||
                category.monthly_budget === undefined
                    ? ''
                    : String(category.monthly_budget),
        })),
    });

    watch(
        () => props.categories,
        () => {
            form.limits = props.categories.map((category) => ({
                category_id: category.id,
                amount:
                    category.monthly_budget === null ||
                    category.monthly_budget === undefined
                        ? ''
                        : String(category.monthly_budget),
            }));
            form.clearErrors();
        },
    );

    let formatMoney = (amount) => {
        if (amount === null || amount === undefined || amount === '') {
            return '—';
        }

        return Number(amount).toLocaleString('en-US', {
            style: 'currency',
            currency: 'USD',
        });
    };

    let annualFromInput = (amount) => {
        if (amount === null || amount === undefined || amount === '') {
            return null;
        }

        let value = Number(amount);

        if (Number.isNaN(value)) {
            return null;
        }

        return value * 12;
    };

    let draftMonthlyTotal = computed(() => {
        return form.limits.reduce((sum, limit) => {
            if (limit.amount === '' || limit.amount === null) {
                return sum;
            }

            let value = Number(limit.amount);

            return Number.isNaN(value) ? sum : sum + value;
        }, 0);
    });

    let draftAnnualTotal = computed(() => draftMonthlyTotal.value * 12);

    let cardStyle = (color) => {
        if (!color) {
            return undefined;
        }

        return {
            borderLeftColor: color,
            backgroundColor: `${color}14`,
        };
    };

    let categoryById = computed(() => {
        let map = {};

        for (let category of props.categories) {
            map[category.id] = category;
        }

        return map;
    });

    let save = () => {
        form
            .transform((data) => ({
                limits: data.limits.map((limit) => ({
                    category_id: limit.category_id,
                    amount:
                        limit.amount === '' || limit.amount === null
                            ? null
                            : limit.amount,
                })),
            }))
            .put('/budgets', {
                preserveScroll: true,
            });
    };
</script>

<template>
    <div class="space-y-8">
        <div class="flex flex-wrap items-start justify-between gap-4">
            <div>
                <h1 class="text-2xl font-semibold">Budgets</h1>
                <p class="text-sm text-neutral-600">
                    Set monthly expense budgets for {{ year }}. Progress is
                    tracked on Home.
                </p>
            </div>
            <button
                type="button"
                class="rounded bg-neutral-900 px-4 py-2 text-sm text-white disabled:opacity-50"
                :disabled="form.processing"
                @click="save"
            >
                Save budgets
            </button>
        </div>

        <div
            v-if="flashSuccess"
            class="rounded border border-emerald-200 bg-emerald-50 px-3 py-2 text-sm text-emerald-900"
        >
            {{ flashSuccess }}
        </div>

        <div class="space-y-3 rounded border px-4 py-3">
            <div class="grid gap-3 sm:grid-cols-2">
                <div>
                    <p class="text-sm text-neutral-600">Monthly total</p>
                    <p class="text-xl font-semibold tabular-nums">
                        {{ formatMoney(draftMonthlyTotal) }}
                    </p>
                </div>
                <div>
                    <p class="text-sm text-neutral-600">Annual total</p>
                    <p class="text-xl font-semibold tabular-nums">
                        {{ formatMoney(draftAnnualTotal) }}
                    </p>
                </div>
            </div>
            <p class="text-sm text-neutral-600">
                Annual amounts are monthly × 12 for planning.
            </p>
        </div>

        <form class="space-y-4" @submit.prevent="save">
            <div class="flex flex-wrap items-baseline justify-between gap-2">
                <h2 class="text-lg font-semibold">Expenses</h2>
                <p class="text-sm font-medium tabular-nums text-neutral-700">
                    {{ formatMoney(draftMonthlyTotal) }} / mo
                </p>
            </div>

            <div
                v-if="categories.length === 0"
                class="text-sm text-neutral-600"
            >
                No expense categories yet.
                <Link href="/categories/create?kind=expense" class="underline">
                    Create one
                </Link>
            </div>

            <div
                v-else
                class="grid gap-3 sm:grid-cols-2 lg:grid-cols-3"
            >
                <div
                    v-for="(limit, index) in form.limits"
                    :key="limit.category_id"
                    class="rounded border border-l-4 px-4 py-3"
                    :class="
                        categoryById[limit.category_id]?.color
                            ? ''
                            : 'border-l-neutral-300 border-dashed bg-neutral-50'
                    "
                    :style="cardStyle(categoryById[limit.category_id]?.color)"
                >
                    <p class="font-medium">
                        {{ categoryById[limit.category_id]?.name }}
                    </p>

                    <label class="mt-3 block text-sm">
                        <span class="text-neutral-600">Monthly budget</span>
                        <input
                            v-model="form.limits[index].amount"
                            type="number"
                            min="0"
                            step="0.01"
                            class="mt-1 block w-full rounded border px-2 py-1.5"
                            placeholder="None"
                        />
                    </label>

                    <p class="mt-2 text-sm text-neutral-600 tabular-nums">
                        {{ formatMoney(annualFromInput(limit.amount)) }} / year
                    </p>

                    <p
                        v-if="form.errors[`limits.${index}.category_id`]"
                        class="mt-2 text-sm text-red-700"
                    >
                        {{ form.errors[`limits.${index}.category_id`] }}
                    </p>
                    <p
                        v-if="form.errors[`limits.${index}.amount`]"
                        class="mt-2 text-sm text-red-700"
                    >
                        {{ form.errors[`limits.${index}.amount`] }}
                    </p>
                </div>
            </div>
        </form>
    </div>
</template>
