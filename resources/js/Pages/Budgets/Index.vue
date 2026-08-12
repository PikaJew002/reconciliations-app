<script setup>
    import AuthenticatedLayout from '../../Layouts/AuthenticatedLayout.vue';
    import { Link, router, useForm, usePage } from '@inertiajs/vue3';
    import { computed, ref, watch } from 'vue';

    defineOptions({ layout: AuthenticatedLayout });

    let props = defineProps({
        budget_year: {
            type: Object,
            default: null,
        },
        budget_years: {
            type: Array,
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
        budget_year_id: props.budget_year?.id ?? null,
        limits: props.categories.map((category) => ({
            category_id: category.id,
            amount:
                category.monthly_budget === null ||
                category.monthly_budget === undefined
                    ? ''
                    : String(category.monthly_budget),
        })),
    });

    let createForm = useForm({
        starts_on: '',
        color: '#336699',
        label: '',
        make_current: true,
    });

    let editForm = useForm({
        label: props.budget_year?.label ?? '',
        color: props.budget_year?.color ?? '#336699',
        starts_on: props.budget_year
            ? props.budget_year.starts_on.slice(0, 7)
            : '',
    });

    let showCreate = ref(props.budget_years.length === 0);
    let showEdit = ref(false);

    watch(
        () => [props.budget_year, props.categories],
        () => {
            form.budget_year_id = props.budget_year?.id ?? null;
            form.limits = props.categories.map((category) => ({
                category_id: category.id,
                amount:
                    category.monthly_budget === null ||
                    category.monthly_budget === undefined
                        ? ''
                        : String(category.monthly_budget),
            }));
            form.clearErrors();

            editForm.label = props.budget_year?.label ?? '';
            editForm.color = props.budget_year?.color ?? '#336699';
            editForm.starts_on = props.budget_year
                ? props.budget_year.starts_on.slice(0, 7)
                : '';
            editForm.clearErrors();
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

    let chipStyle = (color) => {
        if (!color) {
            return undefined;
        }

        return {
            borderColor: color,
            backgroundColor: `${color}22`,
        };
    };

    let categoryById = computed(() => {
        let map = {};

        for (let category of props.categories) {
            map[category.id] = category;
        }

        return map;
    });

    let selectYear = (budgetYearId) => {
        router.get(
            '/budgets',
            { budget_year_id: budgetYearId },
            { preserveState: true, replace: true },
        );
    };

    let save = () => {
        if (!form.budget_year_id) {
            return;
        }

        form
            .transform((data) => ({
                budget_year_id: data.budget_year_id,
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

    let createYear = () => {
        createForm.post('/budgets/years', {
            preserveScroll: true,
            onSuccess: () => {
                showCreate.value = false;
                createForm.reset();
                createForm.color = '#336699';
                createForm.make_current = true;
            },
        });
    };

    let saveYearMeta = () => {
        if (!props.budget_year) {
            return;
        }

        editForm.patch(`/budgets/years/${props.budget_year.id}`, {
            preserveScroll: true,
            onSuccess: () => {
                showEdit.value = false;
            },
        });
    };

    let makeCurrent = () => {
        if (!props.budget_year) {
            return;
        }

        router.post(`/budgets/years/${props.budget_year.id}/current`);
    };
</script>

<template>
    <div class="space-y-8">
        <div class="flex flex-wrap items-start justify-between gap-4">
            <div>
                <h1 class="text-2xl font-semibold">Budgets</h1>
                <p class="text-sm text-neutral-600">
                    Set monthly expense budgets for a 12-month budget year.
                    Progress is tracked on Home.
                </p>
            </div>
            <div class="flex flex-wrap gap-2">
                <button
                    type="button"
                    class="rounded border px-3 py-1.5 text-sm hover:bg-neutral-50"
                    @click="showCreate = !showCreate"
                >
                    New budget year
                </button>
                <button
                    v-if="budget_year"
                    type="button"
                    class="rounded bg-neutral-900 px-4 py-2 text-sm text-white disabled:opacity-50"
                    :disabled="form.processing"
                    @click="save"
                >
                    Save budgets
                </button>
            </div>
        </div>

        <div
            v-if="flashSuccess"
            class="rounded border border-emerald-200 bg-emerald-50 px-3 py-2 text-sm text-emerald-900"
        >
            {{ flashSuccess }}
        </div>

        <div
            v-if="showCreate"
            class="space-y-3 rounded border px-4 py-3"
        >
            <p class="text-sm font-medium">Create budget year</p>
            <div class="grid gap-3 sm:grid-cols-2 lg:grid-cols-4">
                <label class="block text-sm">
                    <span class="text-neutral-600">Starts</span>
                    <input
                        v-model="createForm.starts_on"
                        type="month"
                        class="mt-1 block w-full rounded border px-2 py-1.5"
                    />
                </label>
                <label class="block text-sm">
                    <span class="text-neutral-600">Color</span>
                    <input
                        v-model="createForm.color"
                        type="color"
                        class="mt-1 block h-10 w-full rounded border px-1 py-1"
                    />
                </label>
                <label class="block text-sm sm:col-span-2">
                    <span class="text-neutral-600">Label (optional)</span>
                    <input
                        v-model="createForm.label"
                        type="text"
                        class="mt-1 block w-full rounded border px-2 py-1.5"
                        placeholder="Auto from start month"
                    />
                </label>
            </div>
            <label class="flex items-center gap-2 text-sm">
                <input v-model="createForm.make_current" type="checkbox" />
                Set as current budget year
            </label>
            <div class="flex gap-2">
                <button
                    type="button"
                    class="rounded bg-neutral-900 px-3 py-1.5 text-sm text-white disabled:opacity-50"
                    :disabled="createForm.processing"
                    @click="createYear"
                >
                    Create
                </button>
                <button
                    v-if="budget_years.length > 0"
                    type="button"
                    class="rounded border px-3 py-1.5 text-sm"
                    @click="showCreate = false"
                >
                    Cancel
                </button>
            </div>
            <p
                v-if="createForm.errors.starts_on"
                class="text-sm text-red-700"
            >
                {{ createForm.errors.starts_on }}
            </p>
        </div>

        <div
            v-if="budget_years.length > 0"
            class="flex flex-wrap items-center gap-2"
        >
            <button
                v-for="year in budget_years"
                :key="year.id"
                type="button"
                class="rounded border px-3 py-1.5 text-sm"
                :class="
                    budget_year?.id === year.id
                        ? 'font-semibold'
                        : 'hover:bg-neutral-50'
                "
                :style="chipStyle(year.color)"
                @click="selectYear(year.id)"
            >
                {{ year.label }}
                <span
                    v-if="year.is_current"
                    class="ml-1 text-xs text-neutral-600"
                >
                    (current)
                </span>
            </button>
        </div>

        <div
            v-if="budget_year"
            class="space-y-3 rounded border px-4 py-3"
            :style="chipStyle(budget_year.color)"
        >
            <div class="flex flex-wrap items-start justify-between gap-3">
                <div>
                    <p class="text-lg font-medium">{{ budget_year.label }}</p>
                    <p class="text-sm text-neutral-600">
                        {{ budget_year.starts_on }} → {{ budget_year.ends_on }}
                        <span v-if="budget_year.is_current"> · Current</span>
                    </p>
                </div>
                <div class="flex flex-wrap gap-2">
                    <button
                        v-if="!budget_year.is_current"
                        type="button"
                        class="rounded border px-3 py-1.5 text-sm hover:bg-white/60"
                        @click="makeCurrent"
                    >
                        Set as current
                    </button>
                    <button
                        type="button"
                        class="rounded border px-3 py-1.5 text-sm hover:bg-white/60"
                        @click="showEdit = !showEdit"
                    >
                        Edit year
                    </button>
                </div>
            </div>

            <div v-if="showEdit" class="grid gap-3 sm:grid-cols-3">
                <label class="block text-sm">
                    <span class="text-neutral-600">Label</span>
                    <input
                        v-model="editForm.label"
                        type="text"
                        class="mt-1 block w-full rounded border bg-white px-2 py-1.5"
                    />
                </label>
                <label class="block text-sm">
                    <span class="text-neutral-600">Starts</span>
                    <input
                        v-model="editForm.starts_on"
                        type="month"
                        class="mt-1 block w-full rounded border bg-white px-2 py-1.5"
                    />
                </label>
                <label class="block text-sm">
                    <span class="text-neutral-600">Color</span>
                    <input
                        v-model="editForm.color"
                        type="color"
                        class="mt-1 block h-10 w-full rounded border bg-white px-1 py-1"
                    />
                </label>
                <div class="sm:col-span-3">
                    <button
                        type="button"
                        class="rounded bg-neutral-900 px-3 py-1.5 text-sm text-white disabled:opacity-50"
                        :disabled="editForm.processing"
                        @click="saveYearMeta"
                    >
                        Save year
                    </button>
                    <p
                        v-if="editForm.errors.starts_on"
                        class="mt-2 text-sm text-red-700"
                    >
                        {{ editForm.errors.starts_on }}
                    </p>
                </div>
            </div>

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
        </div>

        <div
            v-else
            class="rounded border px-4 py-6 text-sm text-neutral-600"
        >
            Create a budget year to start setting monthly category budgets.
        </div>

        <form
            v-if="budget_year"
            class="space-y-4"
            @submit.prevent="save"
        >
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
                </div>
            </div>
        </form>
    </div>
</template>
