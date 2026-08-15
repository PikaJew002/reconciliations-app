<script setup>
    import AuthenticatedLayout from '../../Layouts/AuthenticatedLayout.vue';
    import { Link, router, useForm, usePage } from '@inertiajs/vue3';
    import { computed, ref, watch } from 'vue';

    defineOptions({ layout: AuthenticatedLayout });

    let props = defineProps({
        month: {
            type: String,
            required: true,
        },
        templates: {
            type: Array,
            required: true,
        },
        occurrences: {
            type: Array,
            required: true,
        },
        link_candidates: {
            type: Array,
            required: true,
        },
        categories: {
            type: Array,
            required: true,
        },
        merchants: {
            type: Array,
            required: true,
        },
        match_modes: {
            type: Array,
            required: true,
        },
    });

    let page = usePage();
    let flashSuccess = computed(() => page.props.flash?.success);

    let showCreate = ref(props.templates.length === 0);
    let editingId = ref(null);
    let linkForId = ref(null);
    let linkTransactionId = ref('');

    let createForm = useForm({
        name: 'Paycheck',
        category_id: props.categories[0]?.id ?? '',
        merchant_id: '',
        match_mode: 'description',
        normalized_pattern: '',
        amount: '',
        expected_day: 1,
        expected_amount: '',
        lookback_days: 7,
        lookforward_days: 3,
        is_active: true,
    });

    let editForm = useForm({
        name: '',
        category_id: '',
        merchant_id: '',
        match_mode: 'description',
        normalized_pattern: '',
        amount: '',
        expected_day: 1,
        expected_amount: '',
        lookback_days: 7,
        lookforward_days: 3,
        is_active: true,
    });

    let matchModeLabel = (mode) => {
        return (
            {
                exact_description_and_amount: 'Exact description + amount',
                amount_and_merchant: 'Amount + merchant',
                merchant: 'Merchant',
                description: 'Description',
            }[mode] ?? mode
        );
    };

    let needsPattern = (mode) =>
        mode === 'description' || mode === 'exact_description_and_amount';
    let needsMerchant = (mode) =>
        mode === 'merchant' || mode === 'amount_and_merchant';
    let needsAmount = (mode) =>
        mode === 'exact_description_and_amount' ||
        mode === 'amount_and_merchant';

    let formatMoney = (amount) => {
        return new Intl.NumberFormat('en-US', {
            style: 'currency',
            currency: 'USD',
        }).format(amount);
    };

    let shiftMonth = (delta) => {
        let [year, month] = props.month.split('-').map(Number);
        let date = new Date(year, month - 1 + delta, 1);
        let next = `${date.getFullYear()}-${String(date.getMonth() + 1).padStart(2, '0')}`;

        router.get('/plans', { month: next }, { preserveState: true });
    };

    let startEdit = (template) => {
        editingId.value = template.id;
        editForm.name = template.name;
        editForm.category_id = template.category_id;
        editForm.merchant_id = template.merchant_id ?? '';
        editForm.match_mode = template.match_mode;
        editForm.normalized_pattern = template.normalized_pattern ?? '';
        editForm.amount = template.amount ?? '';
        editForm.expected_day = template.expected_day;
        editForm.expected_amount = template.expected_amount;
        editForm.lookback_days = template.lookback_days;
        editForm.lookforward_days = template.lookforward_days;
        editForm.is_active = template.is_active;
    };

    let payloadFromForm = (form) => ({
        name: form.name,
        category_id: form.category_id,
        merchant_id: form.merchant_id || null,
        match_mode: form.match_mode,
        normalized_pattern: form.normalized_pattern || null,
        amount: form.amount === '' ? null : form.amount,
        expected_day: form.expected_day,
        expected_amount: form.expected_amount,
        lookback_days: form.lookback_days,
        lookforward_days: form.lookforward_days,
        is_active: form.is_active,
    });

    let create = () => {
        createForm
            .transform((data) => payloadFromForm(data))
            .post('/plans', {
                preserveScroll: true,
                onSuccess: () => {
                    showCreate.value = false;
                    createForm.reset();
                    createForm.category_id = props.categories[0]?.id ?? '';
                    createForm.match_mode = 'description';
                    createForm.lookback_days = 7;
                    createForm.lookforward_days = 3;
                    createForm.is_active = true;
                    createForm.name = 'Paycheck';
                },
            });
    };

    let saveEdit = (template) => {
        editForm
            .transform((data) => payloadFromForm(data))
            .patch(`/plans/${template.id}?month=${props.month}`, {
                preserveScroll: true,
                onSuccess: () => {
                    editingId.value = null;
                },
            });
    };

    watch(
        () => createForm.match_mode,
        (mode) => {
            if (mode === 'merchant') {
                createForm.normalized_pattern = '';
            }
        },
    );

    let deleteTemplate = (template) => {
        if (!window.confirm(`Delete paycheck plan "${template.name}"?`)) {
            return;
        }

        router.delete(`/plans/${template.id}`);
    };

    let linkOccurrence = (occurrence) => {
        if (!linkTransactionId.value) {
            return;
        }

        router.post(
            `/plans/occurrences/${occurrence.id}/link`,
            {
                bank_transaction_id: Number(linkTransactionId.value),
                month: props.month,
            },
            {
                preserveScroll: true,
                onSuccess: () => {
                    linkForId.value = null;
                    linkTransactionId.value = '';
                },
            },
        );
    };
</script>

<template>
    <div class="space-y-8">
        <div class="flex flex-wrap items-start justify-between gap-4">
            <div>
                <h1 class="text-2xl font-semibold">Plans</h1>
                <p class="text-sm text-neutral-600">
                    Recurring paychecks that count toward a budget month, even
                    when they post early. Imported credits match these
                    occurrences automatically.
                </p>
            </div>
            <button
                type="button"
                class="rounded border px-3 py-1.5 text-sm hover:bg-neutral-50"
                @click="showCreate = !showCreate"
            >
                {{ showCreate ? 'Cancel' : 'New paycheck plan' }}
            </button>
        </div>

        <div
            v-if="flashSuccess"
            class="rounded border border-emerald-200 bg-emerald-50 px-3 py-2 text-sm text-emerald-900"
        >
            {{ flashSuccess }}
        </div>

        <div
            v-if="categories.length === 0"
            class="rounded border px-4 py-3 text-sm text-neutral-600"
        >
            Create an income category on
            <Link href="/categories?kind=income" class="underline"
                >Categories</Link
            >
            before adding a paycheck plan.
        </div>

        <form
            v-if="showCreate && categories.length > 0"
            class="space-y-3 rounded border px-4 py-3"
            @submit.prevent="create"
        >
            <p class="text-sm font-medium">New paycheck plan</p>
            <div class="grid gap-3 sm:grid-cols-2 lg:grid-cols-3">
                <label class="block text-sm">
                    <span class="text-neutral-600">Name</span>
                    <input
                        v-model="createForm.name"
                        type="text"
                        class="mt-1 w-full rounded border px-3 py-2"
                        required
                    />
                    <span
                        v-if="createForm.errors.name"
                        class="mt-1 block text-red-600"
                        >{{ createForm.errors.name }}</span
                    >
                </label>
                <label class="block text-sm">
                    <span class="text-neutral-600">Category</span>
                    <select
                        v-model="createForm.category_id"
                        class="mt-1 w-full rounded border px-3 py-2"
                        required
                    >
                        <option
                            v-for="category in categories"
                            :key="category.id"
                            :value="category.id"
                        >
                            {{ category.name }}
                        </option>
                    </select>
                    <span
                        v-if="createForm.errors.category_id"
                        class="mt-1 block text-red-600"
                        >{{ createForm.errors.category_id }}</span
                    >
                </label>
                <label class="block text-sm">
                    <span class="text-neutral-600">Expected day</span>
                    <input
                        v-model.number="createForm.expected_day"
                        type="number"
                        min="1"
                        max="31"
                        class="mt-1 w-full rounded border px-3 py-2"
                        required
                    />
                    <span
                        v-if="createForm.errors.expected_day"
                        class="mt-1 block text-red-600"
                        >{{ createForm.errors.expected_day }}</span
                    >
                </label>
                <label class="block text-sm">
                    <span class="text-neutral-600">Expected amount</span>
                    <input
                        v-model="createForm.expected_amount"
                        type="number"
                        min="0"
                        step="0.01"
                        class="mt-1 w-full rounded border px-3 py-2"
                        required
                    />
                    <span
                        v-if="createForm.errors.expected_amount"
                        class="mt-1 block text-red-600"
                        >{{ createForm.errors.expected_amount }}</span
                    >
                </label>
                <label class="block text-sm">
                    <span class="text-neutral-600">Match mode</span>
                    <select
                        v-model="createForm.match_mode"
                        class="mt-1 w-full rounded border px-3 py-2"
                    >
                        <option
                            v-for="mode in match_modes"
                            :key="mode"
                            :value="mode"
                        >
                            {{ matchModeLabel(mode) }}
                        </option>
                    </select>
                </label>
                <label v-if="needsPattern(createForm.match_mode)" class="block text-sm">
                    <span class="text-neutral-600">Memo / description</span>
                    <input
                        v-model="createForm.normalized_pattern"
                        type="text"
                        class="mt-1 w-full rounded border px-3 py-2"
                    />
                    <span
                        v-if="createForm.errors.normalized_pattern"
                        class="mt-1 block text-red-600"
                        >{{ createForm.errors.normalized_pattern }}</span
                    >
                </label>
                <label v-if="needsMerchant(createForm.match_mode)" class="block text-sm">
                    <span class="text-neutral-600">Merchant</span>
                    <select
                        v-model="createForm.merchant_id"
                        class="mt-1 w-full rounded border px-3 py-2"
                    >
                        <option value="">Select merchant</option>
                        <option
                            v-for="merchant in merchants"
                            :key="merchant.id"
                            :value="merchant.id"
                        >
                            {{ merchant.name }}
                        </option>
                    </select>
                    <span
                        v-if="createForm.errors.merchant_id"
                        class="mt-1 block text-red-600"
                        >{{ createForm.errors.merchant_id }}</span
                    >
                </label>
                <label v-if="needsAmount(createForm.match_mode)" class="block text-sm">
                    <span class="text-neutral-600">Exact amount</span>
                    <input
                        v-model="createForm.amount"
                        type="number"
                        min="0"
                        step="0.01"
                        class="mt-1 w-full rounded border px-3 py-2"
                    />
                    <span
                        v-if="createForm.errors.amount"
                        class="mt-1 block text-red-600"
                        >{{ createForm.errors.amount }}</span
                    >
                </label>
                <label class="block text-sm">
                    <span class="text-neutral-600">Look back (days)</span>
                    <input
                        v-model.number="createForm.lookback_days"
                        type="number"
                        min="0"
                        max="31"
                        class="mt-1 w-full rounded border px-3 py-2"
                    />
                </label>
                <label class="block text-sm">
                    <span class="text-neutral-600">Look forward (days)</span>
                    <input
                        v-model.number="createForm.lookforward_days"
                        type="number"
                        min="0"
                        max="31"
                        class="mt-1 w-full rounded border px-3 py-2"
                    />
                </label>
            </div>
            <button
                type="submit"
                class="rounded bg-neutral-900 px-4 py-2 text-sm text-white disabled:opacity-50"
                :disabled="createForm.processing"
            >
                Create plan
            </button>
        </form>

        <section class="space-y-3">
            <div class="flex flex-wrap items-center justify-between gap-3">
                <h2 class="text-lg font-medium">{{ month }} paychecks</h2>
                <div class="flex gap-2">
                    <button
                        type="button"
                        class="rounded border px-3 py-1.5 text-sm hover:bg-neutral-50"
                        @click="shiftMonth(-1)"
                    >
                        Previous
                    </button>
                    <button
                        type="button"
                        class="rounded border px-3 py-1.5 text-sm hover:bg-neutral-50"
                        @click="shiftMonth(1)"
                    >
                        Next
                    </button>
                </div>
            </div>

            <p v-if="occurrences.length === 0" class="text-sm text-neutral-600">
                No paycheck occurrences this month. Add a plan to project them.
            </p>

            <div v-else class="overflow-x-auto rounded border">
                <table class="min-w-full text-left text-sm">
                    <thead class="border-b bg-neutral-50 text-neutral-600">
                        <tr>
                            <th class="px-3 py-2 font-medium">Expected</th>
                            <th class="px-3 py-2 font-medium">Plan</th>
                            <th class="px-3 py-2 font-medium">Status</th>
                            <th class="px-3 py-2 font-medium">Amount</th>
                            <th class="px-3 py-2 font-medium">Posted</th>
                            <th class="px-3 py-2 font-medium"></th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr
                            v-for="occurrence in occurrences"
                            :key="occurrence.id"
                            class="border-b last:border-0"
                        >
                            <td class="px-3 py-2 tabular-nums">
                                {{ occurrence.expected_date }}
                            </td>
                            <td class="px-3 py-2">
                                {{ occurrence.template_name || 'One-off' }}
                            </td>
                            <td class="px-3 py-2 capitalize">
                                {{ occurrence.status }}
                            </td>
                            <td class="px-3 py-2 tabular-nums">
                                {{
                                    formatMoney(
                                        occurrence.bank_transaction
                                            ? occurrence.bank_transaction.amount
                                            : occurrence.expected_amount,
                                    )
                                }}
                            </td>
                            <td class="px-3 py-2 text-neutral-600">
                                <template v-if="occurrence.bank_transaction">
                                    {{ occurrence.bank_transaction.posted_at }}
                                    ·
                                    {{
                                        occurrence.bank_transaction.description
                                    }}
                                </template>
                                <span v-else>—</span>
                            </td>
                            <td class="px-3 py-2">
                                <template
                                    v-if="
                                        occurrence.status === 'planned' &&
                                        link_candidates.length > 0
                                    "
                                >
                                    <div
                                        v-if="linkForId === occurrence.id"
                                        class="flex flex-wrap items-center gap-2"
                                    >
                                        <select
                                            v-model="linkTransactionId"
                                            class="rounded border px-2 py-1"
                                        >
                                            <option value="">
                                                Select credit
                                            </option>
                                            <option
                                                v-for="candidate in link_candidates"
                                                :key="candidate.id"
                                                :value="String(candidate.id)"
                                            >
                                                {{ candidate.posted_at }} ·
                                                {{
                                                    formatMoney(
                                                        candidate.amount,
                                                    )
                                                }}
                                                · {{ candidate.description }}
                                            </option>
                                        </select>
                                        <button
                                            type="button"
                                            class="rounded border px-2 py-1 text-xs"
                                            @click="linkOccurrence(occurrence)"
                                        >
                                            Link
                                        </button>
                                    </div>
                                    <button
                                        v-else
                                        type="button"
                                        class="text-xs underline"
                                        @click="linkForId = occurrence.id"
                                    >
                                        Link transaction
                                    </button>
                                </template>
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </section>

        <section class="space-y-3">
            <h2 class="text-lg font-medium">Paycheck plans</h2>
            <p v-if="templates.length === 0" class="text-sm text-neutral-600">
                No paycheck plans yet.
            </p>
            <div
                v-for="template in templates"
                :key="template.id"
                class="space-y-3 rounded border px-4 py-3"
            >
                <div
                    class="flex flex-wrap items-start justify-between gap-3"
                >
                    <div>
                        <p class="font-medium">
                            {{ template.name }}
                            <span
                                v-if="!template.is_active"
                                class="ml-2 text-xs font-normal text-neutral-500"
                                >Inactive</span
                            >
                        </p>
                        <p class="text-sm text-neutral-600">
                            Day {{ template.expected_day }} ·
                            {{ formatMoney(template.expected_amount) }} ·
                            {{ matchModeLabel(template.match_mode) }}
                            <span v-if="template.normalized_pattern">
                                · {{ template.normalized_pattern }}
                            </span>
                            <span v-if="template.merchant">
                                · {{ template.merchant.name }}
                            </span>
                        </p>
                    </div>
                    <div class="flex gap-2">
                        <button
                            type="button"
                            class="rounded border px-3 py-1.5 text-sm hover:bg-neutral-50"
                            @click="
                                editingId === template.id
                                    ? (editingId = null)
                                    : startEdit(template)
                            "
                        >
                            {{ editingId === template.id ? 'Close' : 'Edit' }}
                        </button>
                        <button
                            type="button"
                            class="rounded border px-3 py-1.5 text-sm text-red-700 hover:bg-red-50"
                            @click="deleteTemplate(template)"
                        >
                            Delete
                        </button>
                    </div>
                </div>

                <form
                    v-if="editingId === template.id"
                    class="grid gap-3 sm:grid-cols-2 lg:grid-cols-3"
                    @submit.prevent="saveEdit(template)"
                >
                    <label class="block text-sm">
                        <span class="text-neutral-600">Name</span>
                        <input
                            v-model="editForm.name"
                            type="text"
                            class="mt-1 w-full rounded border px-3 py-2"
                            required
                        />
                    </label>
                    <label class="block text-sm">
                        <span class="text-neutral-600">Category</span>
                        <select
                            v-model="editForm.category_id"
                            class="mt-1 w-full rounded border px-3 py-2"
                        >
                            <option
                                v-for="category in categories"
                                :key="category.id"
                                :value="category.id"
                            >
                                {{ category.name }}
                            </option>
                        </select>
                    </label>
                    <label class="block text-sm">
                        <span class="text-neutral-600">Expected day</span>
                        <input
                            v-model.number="editForm.expected_day"
                            type="number"
                            min="1"
                            max="31"
                            class="mt-1 w-full rounded border px-3 py-2"
                        />
                    </label>
                    <label class="block text-sm">
                        <span class="text-neutral-600">Expected amount</span>
                        <input
                            v-model="editForm.expected_amount"
                            type="number"
                            min="0"
                            step="0.01"
                            class="mt-1 w-full rounded border px-3 py-2"
                        />
                    </label>
                    <label class="block text-sm">
                        <span class="text-neutral-600">Match mode</span>
                        <select
                            v-model="editForm.match_mode"
                            class="mt-1 w-full rounded border px-3 py-2"
                        >
                            <option
                                v-for="mode in match_modes"
                                :key="mode"
                                :value="mode"
                            >
                                {{ matchModeLabel(mode) }}
                            </option>
                        </select>
                    </label>
                    <label
                        v-if="needsPattern(editForm.match_mode)"
                        class="block text-sm"
                    >
                        <span class="text-neutral-600">Memo / description</span>
                        <input
                            v-model="editForm.normalized_pattern"
                            type="text"
                            class="mt-1 w-full rounded border px-3 py-2"
                        />
                    </label>
                    <label
                        v-if="needsMerchant(editForm.match_mode)"
                        class="block text-sm"
                    >
                        <span class="text-neutral-600">Merchant</span>
                        <select
                            v-model="editForm.merchant_id"
                            class="mt-1 w-full rounded border px-3 py-2"
                        >
                            <option value="">Select merchant</option>
                            <option
                                v-for="merchant in merchants"
                                :key="merchant.id"
                                :value="merchant.id"
                            >
                                {{ merchant.name }}
                            </option>
                        </select>
                    </label>
                    <label
                        v-if="needsAmount(editForm.match_mode)"
                        class="block text-sm"
                    >
                        <span class="text-neutral-600">Exact amount</span>
                        <input
                            v-model="editForm.amount"
                            type="number"
                            min="0"
                            step="0.01"
                            class="mt-1 w-full rounded border px-3 py-2"
                        />
                    </label>
                    <label class="block text-sm">
                        <span class="text-neutral-600">Look back (days)</span>
                        <input
                            v-model.number="editForm.lookback_days"
                            type="number"
                            min="0"
                            max="31"
                            class="mt-1 w-full rounded border px-3 py-2"
                        />
                    </label>
                    <label class="block text-sm">
                        <span class="text-neutral-600">Look forward (days)</span>
                        <input
                            v-model.number="editForm.lookforward_days"
                            type="number"
                            min="0"
                            max="31"
                            class="mt-1 w-full rounded border px-3 py-2"
                        />
                    </label>
                    <label class="flex items-center gap-2 text-sm">
                        <input v-model="editForm.is_active" type="checkbox" />
                        Active
                    </label>
                    <div class="sm:col-span-2 lg:col-span-3">
                        <button
                            type="submit"
                            class="rounded bg-neutral-900 px-4 py-2 text-sm text-white disabled:opacity-50"
                            :disabled="editForm.processing"
                        >
                            Save plan
                        </button>
                    </div>
                </form>
            </div>
        </section>
    </div>
</template>
