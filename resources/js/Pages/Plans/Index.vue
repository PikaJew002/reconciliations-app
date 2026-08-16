<script setup>
    import AuthenticatedLayout from '../../Layouts/AuthenticatedLayout.vue';
    import StickyToasts from '../../Components/StickyToasts.vue';
    import { Link, router, useForm, usePage } from '@inertiajs/vue3';
    import { computed, onMounted, onUnmounted, ref, watch } from 'vue';

    defineOptions({ layout: AuthenticatedLayout });

    let props = defineProps({
        month: {
            type: String,
            required: true,
        },
        paycheck_templates: {
            type: Array,
            required: true,
        },
        bill_templates: {
            type: Array,
            required: true,
        },
        paycheck_occurrences: {
            type: Array,
            required: true,
        },
        bill_occurrences: {
            type: Array,
            required: true,
        },
        paycheck_link_candidates: {
            type: Array,
            required: true,
        },
        bill_link_candidates: {
            type: Array,
            required: true,
        },
        categories: {
            type: Array,
            required: true,
        },
        bill_categories: {
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
        bill_match_modes: {
            type: Array,
            required: true,
        },
        source_transactions: {
            type: Object,
            default: () => ({}),
        },
        active_match_runs: {
            type: Array,
            default: () => [],
        },
        month_in_budget_year: {
            type: Boolean,
            default: false,
        },
        month_beyond_occurrence_horizon: {
            type: Boolean,
            default: false,
        },
    });

    let page = usePage();
    let flashSuccess = computed(() => page.props.flash?.success);
    let matchRunsInProgress = computed(() =>
        props.active_match_runs.filter((run) =>
            ['pending', 'processing'].includes(run.status),
        ),
    );
    let hasMatchRunsInProgress = computed(
        () => matchRunsInProgress.value.length > 0,
    );
    let matchPollId = null;
    let toasts = ref([]);
    let nextToastId = 0;
    let toastTimers = new Map();
    let announcedMatchRunIds = new Set();
    let matchProgressToastId = null;

    function matchReloadKeys() {
        return [
            'paycheck_occurrences',
            'bill_occurrences',
            'paycheck_link_candidates',
            'bill_link_candidates',
            'active_match_runs',
        ];
    }

    function startMatchPolling() {
        if (matchPollId || !hasMatchRunsInProgress.value) {
            return;
        }

        matchPollId = window.setInterval(() => {
            router.reload({
                only: matchReloadKeys(),
                preserveScroll: true,
                onSuccess: () => {
                    if (!hasMatchRunsInProgress.value && matchPollId) {
                        stopMatchPolling();
                    }
                },
            });
        }, 2000);
    }

    function stopMatchPolling() {
        if (matchPollId) {
            window.clearInterval(matchPollId);
            matchPollId = null;
        }
    }

    function dismissToast(id) {
        toasts.value = toasts.value.filter((toast) => toast.id !== id);

        let timer = toastTimers.get(id);
        if (timer) {
            window.clearTimeout(timer);
            toastTimers.delete(id);
        }

        if (matchProgressToastId === id) {
            matchProgressToastId = null;
        }
    }

    function pushToast({ type, message, persistent = false, duration = 6000 }) {
        let id = ++nextToastId;
        toasts.value = [...toasts.value, { id, type, message, persistent }];

        if (!persistent) {
            let timer = window.setTimeout(() => dismissToast(id), duration);
            toastTimers.set(id, timer);
        }

        return id;
    }

    function matchProgressMessage() {
        let count = matchRunsInProgress.value.length;

        return `Matching existing transactions to plan occurrences (${count} active)… linked transactions will update automatically.`;
    }

    function syncMatchProgressToast() {
        if (!hasMatchRunsInProgress.value) {
            if (matchProgressToastId !== null) {
                dismissToast(matchProgressToastId);
            }

            return;
        }

        let message = matchProgressMessage();

        if (matchProgressToastId === null) {
            matchProgressToastId = pushToast({
                type: 'warning',
                message,
                persistent: true,
            });
            return;
        }

        toasts.value = toasts.value.map((item) =>
            item.id === matchProgressToastId ? { ...item, message } : item,
        );
    }

    function completedMatchMessage(run) {
        let matched = run.metadata?.matched ?? 0;

        return `Plan matching finished. Linked ${matched} transaction${
            matched === 1 ? '' : 's'
        }.`;
    }

    function announceFinishedMatchRuns() {
        for (let run of props.active_match_runs) {
            if (announcedMatchRunIds.has(run.id)) {
                continue;
            }

            if (run.status === 'completed') {
                announcedMatchRunIds.add(run.id);
                pushToast({
                    type: 'success',
                    message: completedMatchMessage(run),
                    duration: 8000,
                });
                continue;
            }

            if (run.status === 'failed') {
                announcedMatchRunIds.add(run.id);
                pushToast({
                    type: 'error',
                    message: `Plan matching failed${
                        run.error_message ? `: ${run.error_message}` : '.'
                    }`,
                    duration: 8000,
                });
            }
        }
    }

    onMounted(() => {
        for (let run of props.active_match_runs) {
            if (run.status === 'completed' || run.status === 'failed') {
                announcedMatchRunIds.add(run.id);
            }
        }

        syncMatchProgressToast();

        if (hasMatchRunsInProgress.value) {
            startMatchPolling();
        }
    });

    watch(hasMatchRunsInProgress, (inProgress) => {
        syncMatchProgressToast();

        if (inProgress) {
            startMatchPolling();
            return;
        }

        stopMatchPolling();
    });

    watch(
        () => props.active_match_runs,
        () => {
            syncMatchProgressToast();
            announceFinishedMatchRuns();
        },
        { deep: true },
    );

    onUnmounted(() => {
        stopMatchPolling();

        for (let timer of toastTimers.values()) {
            window.clearTimeout(timer);
        }
        toastTimers.clear();
    });

    let showCreate = ref(
        props.paycheck_templates.length === 0 &&
            props.bill_templates.length === 0
            ? 'paycheck'
            : null,
    );
    let editingId = ref(null);
    let linkForId = ref(null);
    let linkTransactionId = ref('');
    let paycheckSourceId = ref('');
    let billSourceId = ref('');

    let emptyPaycheckForm = () => ({
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

    let emptyBillForm = () => ({
        name: '',
        category_id: props.bill_categories[0]?.id ?? '',
        merchant_id: '',
        match_mode: 'description_prefix_and_amount',
        normalized_pattern: '',
        amount: '',
        expected_day: 1,
        expected_amount: '',
        lookback_days: 7,
        lookforward_days: 3,
        is_active: true,
    });

    let createPaycheckForm = useForm(emptyPaycheckForm());
    let createBillForm = useForm(emptyBillForm());
    let editForm = useForm(emptyPaycheckForm());

    let matchModeLabel = (mode) => {
        return (
            {
                exact_description_and_amount: 'Exact description + amount',
                amount_and_merchant: 'Amount + merchant',
                merchant: 'Merchant',
                description: 'Description',
                description_prefix_and_amount: 'Description prefix + amount',
                check_and_amount: 'Check + amount',
            }[mode] ?? mode
        );
    };

    let needsPattern = (mode) =>
        mode === 'description' ||
        mode === 'exact_description_and_amount' ||
        mode === 'description_prefix_and_amount';
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

    let sourceTransactionsFor = (categoryId) => {
        if (!categoryId) {
            return [];
        }

        return (
            props.source_transactions[categoryId] ??
            props.source_transactions[String(categoryId)] ??
            []
        );
    };

    let applySourceTransaction = (form, option) => {
        if (!option) {
            return;
        }

        form.expected_day = option.expected_day;
        form.expected_amount = option.amount;

        if (option.match_mode) {
            form.match_mode = option.match_mode;
            form.normalized_pattern = option.normalized_pattern ?? '';
            form.merchant_id = option.merchant_id ?? '';
            form.amount = option.match_amount ?? option.amount;
        } else {
            if (needsPattern(form.match_mode) && option.normalized_pattern) {
                form.normalized_pattern = option.normalized_pattern;
            }

            if (needsMerchant(form.match_mode) && option.merchant_id) {
                form.merchant_id = option.merchant_id;
            }

            if (needsAmount(form.match_mode)) {
                form.amount = option.match_amount ?? option.amount;
            }
        }

        if (!form.name || form.name === 'Paycheck') {
            form.name = option.suggested_name || option.description || form.name;
        }
    };

    let onPaycheckSourceChange = () => {
        let option = sourceTransactionsFor(
            createPaycheckForm.category_id,
        ).find((item) => String(item.id) === String(paycheckSourceId.value));

        applySourceTransaction(createPaycheckForm, option);
    };

    let onBillSourceChange = () => {
        let option = sourceTransactionsFor(createBillForm.category_id).find(
            (item) => String(item.id) === String(billSourceId.value),
        );

        applySourceTransaction(createBillForm, option);
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

    let payloadFromForm = (form, kind) => ({
        name: form.name,
        category_id: form.category_id,
        merchant_id: form.merchant_id || null,
        match_mode: form.match_mode,
        normalized_pattern: form.normalized_pattern || null,
        amount:
            kind === 'bill'
                ? form.expected_amount
                : form.amount === ''
                  ? null
                  : form.amount,
        expected_day: form.expected_day,
        expected_amount: form.expected_amount,
        lookback_days: form.lookback_days,
        lookforward_days: form.lookforward_days,
        is_active: form.is_active,
    });

    let createPaycheck = () => {
        createPaycheckForm
            .transform((data) => payloadFromForm(data, 'paycheck'))
            .post('/plans', {
                preserveScroll: true,
                onSuccess: () => {
                    showCreate.value = null;
                    createPaycheckForm.reset();
                    Object.assign(createPaycheckForm, emptyPaycheckForm());
                    paycheckSourceId.value = '';
                },
            });
    };

    let createBill = () => {
        createBillForm
            .transform((data) => payloadFromForm(data, 'bill'))
            .post('/plans', {
                preserveScroll: true,
                onSuccess: () => {
                    showCreate.value = null;
                    createBillForm.reset();
                    Object.assign(createBillForm, emptyBillForm());
                    billSourceId.value = '';
                },
            });
    };

    let saveEdit = (template) => {
        let kind =
            template.classification === 'bill' ? 'bill' : 'paycheck';

        editForm
            .transform((data) => payloadFromForm(data, kind))
            .patch(`/plans/${template.id}?month=${props.month}`, {
                preserveScroll: true,
                onSuccess: () => {
                    editingId.value = null;
                },
            });
    };

    watch(
        () => createPaycheckForm.match_mode,
        (mode) => {
            if (mode === 'merchant') {
                createPaycheckForm.normalized_pattern = '';
            }
        },
    );

    watch(
        () => createPaycheckForm.category_id,
        () => {
            paycheckSourceId.value = '';
        },
    );

    watch(
        () => createBillForm.match_mode,
        (mode) => {
            if (mode === 'merchant' || mode === 'check_and_amount') {
                createBillForm.normalized_pattern = '';
            }
        },
    );

    watch(
        () => createBillForm.category_id,
        () => {
            billSourceId.value = '';
        },
    );

    let deleteTemplate = (template) => {
        let kind =
            template.classification === 'bill' ? 'bill' : 'paycheck';

        if (!window.confirm(`Delete ${kind} plan "${template.name}"?`)) {
            return;
        }

        router.delete(`/plans/${template.id}`);
    };

    let selectedBillIdsByPaycheck = ref({});
    let savingAssignmentsFor = ref(null);
    let assignmentErrorPaycheckId = ref(null);

    let syncAssignmentSelections = () => {
        let next = {};

        for (let template of props.paycheck_templates) {
            next[template.id] = (template.assigned_bill_ids ?? []).map(Number);
        }

        selectedBillIdsByPaycheck.value = next;
    };

    watch(
        () => props.paycheck_templates,
        syncAssignmentSelections,
        { immediate: true, deep: true },
    );

    let selectedBillIds = (paycheck) =>
        selectedBillIdsByPaycheck.value[paycheck.id] ??
        paycheck.assigned_bill_ids ??
        [];

    let isBillSelected = (paycheck, bill) =>
        selectedBillIds(paycheck).includes(Number(bill.id));

    let otherPaycheckForBill = (paycheck, bill) => {
        if (!bill.assigned_paycheck) {
            return null;
        }

        if (Number(bill.assigned_paycheck.id) === Number(paycheck.id)) {
            return null;
        }

        return bill.assigned_paycheck;
    };

    let assignedBillsTotal = (paycheck) => {
        let ids = selectedBillIds(paycheck);

        return props.bill_templates
            .filter((bill) => ids.includes(Number(bill.id)) && bill.is_active)
            .reduce((sum, bill) => sum + Number(bill.expected_amount), 0);
    };

    let paycheckLeftover = (paycheck) =>
        Math.round(
            (Number(paycheck.expected_amount) - assignedBillsTotal(paycheck)) *
                100,
        ) / 100;

    let leftoverClass = (amount) =>
        amount < 0 ? 'text-red-700' : 'text-emerald-800';

    let occurrencesPendingThisMonth = computed(
        () =>
            props.month_in_budget_year &&
            props.month_beyond_occurrence_horizon,
    );

    let pendingOccurrencesCopy = (kind) =>
        `No ${kind} occurrences for this month yet. Occurrences are generated from last month through two months ahead, so this month will appear when it gets closer.`;

    let emptyOccurrencesCopy = (kind) =>
        occurrencesPendingThisMonth.value
            ? pendingOccurrencesCopy(kind)
            : `No ${kind} occurrences this month. Add a plan to project them.`;

    let unassignedBills = computed(() =>
        props.bill_templates.filter((bill) => !bill.assigned_paycheck),
    );

    let assignmentError = computed(
        () => page.props.errors?.bill_template_ids ?? null,
    );

    let saveAssignments = (paycheck, nextIds) => {
        let previous = selectedBillIds(paycheck);
        selectedBillIdsByPaycheck.value = {
            ...selectedBillIdsByPaycheck.value,
            [paycheck.id]: nextIds,
        };
        savingAssignmentsFor.value = paycheck.id;

        router.put(
            `/plans/${paycheck.id}/assignments`,
            {
                bill_template_ids: nextIds,
                month: props.month,
            },
            {
                preserveScroll: true,
                onSuccess: () => {
                    if (assignmentErrorPaycheckId.value === paycheck.id) {
                        assignmentErrorPaycheckId.value = null;
                    }
                },
                onError: () => {
                    assignmentErrorPaycheckId.value = paycheck.id;
                    selectedBillIdsByPaycheck.value = {
                        ...selectedBillIdsByPaycheck.value,
                        [paycheck.id]: previous,
                    };
                },
                onFinish: () => {
                    if (savingAssignmentsFor.value === paycheck.id) {
                        savingAssignmentsFor.value = null;
                    }
                },
            },
        );
    };

    let toggleBillAssignment = (paycheck, bill) => {
        if (otherPaycheckForBill(paycheck, bill)) {
            return;
        }

        let current = selectedBillIds(paycheck);
        let billId = Number(bill.id);
        let next = current.includes(billId)
            ? current.filter((id) => id !== billId)
            : [...current, billId];

        saveAssignments(paycheck, next);
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
        <StickyToasts :toasts="toasts" @dismiss="dismissToast" />

        <div class="flex flex-wrap items-start justify-between gap-4">
            <div>
                <h1 class="text-2xl font-semibold">Plans</h1>
                <p class="text-sm text-neutral-600">
                    Recurring paychecks and bills that count toward a budget
                    month, even when they post early or late. Imported
                    transactions match these occurrences automatically.
                </p>
            </div>
            <div class="flex flex-wrap gap-2">
                <button
                    type="button"
                    class="rounded border px-3 py-1.5 text-sm hover:bg-neutral-50"
                    @click="
                        showCreate = showCreate === 'paycheck' ? null : 'paycheck'
                    "
                >
                    {{
                        showCreate === 'paycheck'
                            ? 'Cancel'
                            : 'New paycheck plan'
                    }}
                </button>
                <button
                    type="button"
                    class="rounded border px-3 py-1.5 text-sm hover:bg-neutral-50"
                    @click="
                        showCreate = showCreate === 'bill' ? null : 'bill'
                    "
                >
                    {{ showCreate === 'bill' ? 'Cancel' : 'New bill plan' }}
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
            v-if="showCreate === 'paycheck' && categories.length === 0"
            class="rounded border px-4 py-3 text-sm text-neutral-600"
        >
            Create an income category on
            <Link href="/categories?kind=income" class="underline"
                >Categories</Link
            >
            before adding a paycheck plan.
        </div>

        <div
            v-if="showCreate === 'bill' && bill_categories.length === 0"
            class="rounded border px-4 py-3 text-sm text-neutral-600"
        >
            Create a bill category on
            <Link href="/categories?kind=bill" class="underline"
                >Categories</Link
            >
            before adding a bill plan.
        </div>

        <form
            v-if="showCreate === 'paycheck' && categories.length > 0"
            class="space-y-3 rounded border px-4 py-3"
            @submit.prevent="createPaycheck"
        >
            <p class="text-sm font-medium">New paycheck plan</p>
            <div class="grid gap-3 sm:grid-cols-2 lg:grid-cols-3">
                <label class="block text-sm">
                    <span class="text-neutral-600">Name</span>
                    <input
                        v-model="createPaycheckForm.name"
                        type="text"
                        class="mt-1 w-full rounded border px-3 py-2"
                        required
                    />
                    <span
                        v-if="createPaycheckForm.errors.name"
                        class="mt-1 block text-red-600"
                        >{{ createPaycheckForm.errors.name }}</span
                    >
                </label>
                <label class="block text-sm">
                    <span class="text-neutral-600">Category</span>
                    <select
                        v-model="createPaycheckForm.category_id"
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
                        v-if="createPaycheckForm.errors.category_id"
                        class="mt-1 block text-red-600"
                        >{{ createPaycheckForm.errors.category_id }}</span
                    >
                </label>
                <label
                    v-if="
                        sourceTransactionsFor(createPaycheckForm.category_id)
                            .length
                    "
                    class="block text-sm sm:col-span-2 lg:col-span-3"
                >
                    <span class="text-neutral-600">Base on a transaction</span>
                    <select
                        v-model="paycheckSourceId"
                        class="mt-1 w-full rounded border px-3 py-2"
                        @change="onPaycheckSourceChange"
                    >
                        <option value="">Optional — pick a past credit</option>
                        <option
                            v-for="option in sourceTransactionsFor(
                                createPaycheckForm.category_id,
                            )"
                            :key="option.id"
                            :value="String(option.id)"
                        >
                            {{ option.posted_at }} ·
                            {{ formatMoney(option.amount) }}
                            <template v-if="option.description">
                                · {{ option.description }}
                            </template>
                        </option>
                    </select>
                </label>
                <label class="block text-sm">
                    <span class="text-neutral-600">Expected day</span>
                    <input
                        v-model.number="createPaycheckForm.expected_day"
                        type="number"
                        min="1"
                        max="31"
                        class="mt-1 w-full rounded border px-3 py-2"
                        required
                    />
                </label>
                <label class="block text-sm">
                    <span class="text-neutral-600">Expected amount</span>
                    <input
                        v-model="createPaycheckForm.expected_amount"
                        type="number"
                        min="0"
                        step="0.01"
                        class="mt-1 w-full rounded border px-3 py-2"
                        required
                    />
                </label>
                <label class="block text-sm">
                    <span class="text-neutral-600">Match mode</span>
                    <select
                        v-model="createPaycheckForm.match_mode"
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
                    v-if="needsPattern(createPaycheckForm.match_mode)"
                    class="block text-sm"
                >
                    <span class="text-neutral-600">Memo / description</span>
                    <input
                        v-model="createPaycheckForm.normalized_pattern"
                        type="text"
                        class="mt-1 w-full rounded border px-3 py-2"
                    />
                    <span
                        v-if="createPaycheckForm.errors.normalized_pattern"
                        class="mt-1 block text-red-600"
                        >{{ createPaycheckForm.errors.normalized_pattern }}</span
                    >
                </label>
                <label
                    v-if="needsMerchant(createPaycheckForm.match_mode)"
                    class="block text-sm"
                >
                    <span class="text-neutral-600">Merchant</span>
                    <select
                        v-model="createPaycheckForm.merchant_id"
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
                    v-if="needsAmount(createPaycheckForm.match_mode)"
                    class="block text-sm"
                >
                    <span class="text-neutral-600">Exact amount</span>
                    <input
                        v-model="createPaycheckForm.amount"
                        type="number"
                        min="0"
                        step="0.01"
                        class="mt-1 w-full rounded border px-3 py-2"
                    />
                </label>
                <label class="block text-sm">
                    <span class="text-neutral-600">Look back (days)</span>
                    <input
                        v-model.number="createPaycheckForm.lookback_days"
                        type="number"
                        min="0"
                        max="31"
                        class="mt-1 w-full rounded border px-3 py-2"
                    />
                </label>
                <label class="block text-sm">
                    <span class="text-neutral-600">Look forward (days)</span>
                    <input
                        v-model.number="createPaycheckForm.lookforward_days"
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
                :disabled="createPaycheckForm.processing"
            >
                Create plan
            </button>
        </form>

        <form
            v-if="showCreate === 'bill' && bill_categories.length > 0"
            class="space-y-3 rounded border px-4 py-3"
            @submit.prevent="createBill"
        >
            <p class="text-sm font-medium">New bill plan</p>
            <div class="grid gap-3 sm:grid-cols-2 lg:grid-cols-3">
                <label class="block text-sm">
                    <span class="text-neutral-600">Name</span>
                    <input
                        v-model="createBillForm.name"
                        type="text"
                        class="mt-1 w-full rounded border px-3 py-2"
                        required
                    />
                    <span
                        v-if="createBillForm.errors.name"
                        class="mt-1 block text-red-600"
                        >{{ createBillForm.errors.name }}</span
                    >
                </label>
                <label class="block text-sm">
                    <span class="text-neutral-600">Category</span>
                    <select
                        v-model="createBillForm.category_id"
                        class="mt-1 w-full rounded border px-3 py-2"
                        required
                    >
                        <option
                            v-for="category in bill_categories"
                            :key="category.id"
                            :value="category.id"
                        >
                            {{ category.name }}
                        </option>
                    </select>
                    <span
                        v-if="createBillForm.errors.category_id"
                        class="mt-1 block text-red-600"
                        >{{ createBillForm.errors.category_id }}</span
                    >
                </label>
                <label
                    v-if="
                        sourceTransactionsFor(createBillForm.category_id)
                            .length
                    "
                    class="block text-sm sm:col-span-2 lg:col-span-3"
                >
                    <span class="text-neutral-600">Base on a transaction</span>
                    <select
                        v-model="billSourceId"
                        class="mt-1 w-full rounded border px-3 py-2"
                        @change="onBillSourceChange"
                    >
                        <option value="">Optional — pick a past charge</option>
                        <option
                            v-for="option in sourceTransactionsFor(
                                createBillForm.category_id,
                            )"
                            :key="option.id"
                            :value="String(option.id)"
                        >
                            {{ option.posted_at }} ·
                            {{ formatMoney(option.amount) }}
                            <template v-if="option.description">
                                · {{ option.description }}
                            </template>
                        </option>
                    </select>
                </label>
                <label class="block text-sm">
                    <span class="text-neutral-600">Expected day</span>
                    <input
                        v-model.number="createBillForm.expected_day"
                        type="number"
                        min="1"
                        max="31"
                        class="mt-1 w-full rounded border px-3 py-2"
                        required
                    />
                </label>
                <label class="block text-sm">
                    <span class="text-neutral-600">Expected amount</span>
                    <input
                        v-model="createBillForm.expected_amount"
                        type="number"
                        min="0"
                        step="0.01"
                        class="mt-1 w-full rounded border px-3 py-2"
                        required
                    />
                    <span
                        v-if="createBillForm.errors.expected_amount"
                        class="mt-1 block text-red-600"
                        >{{ createBillForm.errors.expected_amount }}</span
                    >
                </label>
                <label class="block text-sm">
                    <span class="text-neutral-600">Match mode</span>
                    <select
                        v-model="createBillForm.match_mode"
                        class="mt-1 w-full rounded border px-3 py-2"
                    >
                        <option
                            v-for="mode in bill_match_modes"
                            :key="mode"
                            :value="mode"
                        >
                            {{ matchModeLabel(mode) }}
                        </option>
                    </select>
                </label>
                <label
                    v-if="needsPattern(createBillForm.match_mode)"
                    class="block text-sm"
                >
                    <span class="text-neutral-600">Memo / description</span>
                    <input
                        v-model="createBillForm.normalized_pattern"
                        type="text"
                        class="mt-1 w-full rounded border px-3 py-2"
                    />
                    <span
                        v-if="createBillForm.errors.normalized_pattern"
                        class="mt-1 block text-red-600"
                        >{{ createBillForm.errors.normalized_pattern }}</span
                    >
                </label>
                <label
                    v-if="needsMerchant(createBillForm.match_mode)"
                    class="block text-sm"
                >
                    <span class="text-neutral-600">Merchant</span>
                    <select
                        v-model="createBillForm.merchant_id"
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
                <label class="block text-sm">
                    <span class="text-neutral-600">Look back (days)</span>
                    <input
                        v-model.number="createBillForm.lookback_days"
                        type="number"
                        min="0"
                        max="31"
                        class="mt-1 w-full rounded border px-3 py-2"
                    />
                </label>
                <label class="block text-sm">
                    <span class="text-neutral-600">Look forward (days)</span>
                    <input
                        v-model.number="createBillForm.lookforward_days"
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
                :disabled="createBillForm.processing"
            >
                Create plan
            </button>
        </form>

        <section class="space-y-6">
            <div class="flex flex-wrap items-center justify-between gap-3">
                <h2 class="text-lg font-medium">{{ month }}</h2>
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

            <div class="space-y-3">
                <h3 class="font-medium">Paychecks</h3>
                <p
                    v-if="paycheck_occurrences.length === 0"
                    class="text-sm text-neutral-600"
                >
                    {{ emptyOccurrencesCopy('paycheck') }}
                </p>
                <div
                    v-else
                    class="overflow-x-auto rounded border"
                >
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
                                v-for="occurrence in paycheck_occurrences"
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
                                    {{ formatMoney(occurrence.amount) }}
                                </td>
                                <td class="px-3 py-2 text-neutral-600">
                                    <template
                                        v-if="occurrence.bank_transaction"
                                    >
                                        {{
                                            occurrence.bank_transaction
                                                .posted_at
                                        }}
                                        ·
                                        {{
                                            occurrence.bank_transaction
                                                .description
                                        }}
                                    </template>
                                    <span v-else>—</span>
                                </td>
                                <td class="px-3 py-2">
                                    <template
                                        v-if="
                                            occurrence.status === 'planned' &&
                                            paycheck_link_candidates.length > 0
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
                                                    v-for="candidate in paycheck_link_candidates"
                                                    :key="candidate.id"
                                                    :value="
                                                        String(candidate.id)
                                                    "
                                                >
                                                    {{ candidate.posted_at }}
                                                    ·
                                                    {{
                                                        formatMoney(
                                                            candidate.amount,
                                                        )
                                                    }}
                                                    ·
                                                    {{ candidate.description }}
                                                </option>
                                            </select>
                                            <button
                                                type="button"
                                                class="rounded border px-2 py-1 text-xs"
                                                @click="
                                                    linkOccurrence(occurrence)
                                                "
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
            </div>

            <div class="space-y-3">
                <h3 class="font-medium">Bills</h3>
                <p
                    v-if="bill_occurrences.length === 0"
                    class="text-sm text-neutral-600"
                >
                    {{ emptyOccurrencesCopy('bill') }}
                </p>
                <div
                    v-else
                    class="overflow-x-auto rounded border"
                >
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
                                v-for="occurrence in bill_occurrences"
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
                                    {{ formatMoney(occurrence.amount) }}
                                </td>
                                <td class="px-3 py-2 text-neutral-600">
                                    <template
                                        v-if="occurrence.bank_transaction"
                                    >
                                        {{
                                            occurrence.bank_transaction
                                                .posted_at
                                        }}
                                        ·
                                        {{
                                            occurrence.bank_transaction
                                                .description
                                        }}
                                    </template>
                                    <span v-else>—</span>
                                </td>
                                <td class="px-3 py-2">
                                    <template
                                        v-if="
                                            occurrence.status === 'planned' &&
                                            bill_link_candidates.length > 0
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
                                                    Select debit
                                                </option>
                                                <option
                                                    v-for="candidate in bill_link_candidates"
                                                    :key="candidate.id"
                                                    :value="
                                                        String(candidate.id)
                                                    "
                                                >
                                                    {{ candidate.posted_at }}
                                                    ·
                                                    {{
                                                        formatMoney(
                                                            Math.abs(
                                                                candidate.amount,
                                                            ),
                                                        )
                                                    }}
                                                    ·
                                                    {{ candidate.description }}
                                                </option>
                                            </select>
                                            <button
                                                type="button"
                                                class="rounded border px-2 py-1 text-xs"
                                                @click="
                                                    linkOccurrence(occurrence)
                                                "
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
            </div>
        </section>

        <section class="space-y-3">
            <h2 class="text-lg font-medium">Paycheck plans</h2>
            <p
                v-if="
                    paycheck_templates.length > 0 &&
                    unassignedBills.length > 0
                "
                class="text-sm text-neutral-600"
            >
                Unassigned bills:
                {{ unassignedBills.map((bill) => bill.name).join(', ') }}.
            </p>
            <p
                v-if="paycheck_templates.length === 0"
                class="text-sm text-neutral-600"
            >
                No paycheck plans yet.
            </p>
            <div
                v-for="template in paycheck_templates"
                :key="template.id"
                class="space-y-3 rounded border px-4 py-3"
            >
                <div class="flex flex-wrap items-start justify-between gap-3">
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

                <p class="text-sm tabular-nums">
                    Paycheck {{ formatMoney(template.expected_amount) }} −
                    bills {{ formatMoney(assignedBillsTotal(template)) }}
                    =
                    <span
                        class="font-medium"
                        :class="leftoverClass(paycheckLeftover(template))"
                    >
                        leftover {{ formatMoney(paycheckLeftover(template)) }}
                    </span>
                </p>

                <div
                    v-if="bill_templates.length === 0"
                    class="text-sm text-neutral-600"
                >
                    No bill plans to assign.
                </div>
                <div v-else class="space-y-2">
                    <p class="text-sm font-medium">Assigned bills</p>
                    <p
                        v-if="
                            assignmentErrorPaycheckId === template.id &&
                            assignmentError
                        "
                        class="text-sm text-red-600"
                    >
                        {{ assignmentError }}
                    </p>
                    <label
                        v-for="bill in bill_templates"
                        :key="bill.id"
                        class="flex items-start gap-2 text-sm"
                        :class="
                            otherPaycheckForBill(template, bill)
                                ? 'text-neutral-400'
                                : ''
                        "
                    >
                        <input
                            type="checkbox"
                            class="mt-0.5"
                            :checked="isBillSelected(template, bill)"
                            :disabled="
                                Boolean(otherPaycheckForBill(template, bill)) ||
                                savingAssignmentsFor === template.id
                            "
                            @change="toggleBillAssignment(template, bill)"
                        />
                        <span>
                            {{ bill.name }} · Day {{ bill.expected_day }} ·
                            {{ formatMoney(bill.expected_amount) }}
                            <span
                                v-if="!bill.is_active"
                                class="text-neutral-500"
                            >
                                (Inactive)
                            </span>
                            <span
                                v-if="otherPaycheckForBill(template, bill)"
                                class="text-neutral-500"
                            >
                                · Assigned to
                                {{
                                    otherPaycheckForBill(template, bill).name
                                }}
                            </span>
                        </span>
                    </label>
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

        <section class="space-y-3">
            <h2 class="text-lg font-medium">Bill plans</h2>
            <p
                v-if="bill_templates.length === 0"
                class="text-sm text-neutral-600"
            >
                No bill plans yet.
            </p>
            <div
                v-for="template in bill_templates"
                :key="template.id"
                class="space-y-3 rounded border px-4 py-3"
            >
                <div class="flex flex-wrap items-start justify-between gap-3">
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
                        <p class="text-sm text-neutral-600">
                            {{
                                template.assigned_paycheck
                                    ? `Assigned to ${template.assigned_paycheck.name}`
                                    : 'Unassigned'
                            }}
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
                                v-for="category in bill_categories"
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
                                v-for="mode in bill_match_modes"
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
