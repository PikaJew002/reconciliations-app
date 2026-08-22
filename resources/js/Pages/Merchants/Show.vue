<script setup>
    import AuthenticatedLayout from '../../Layouts/AuthenticatedLayout.vue';
    import { Link, router, useForm, usePage } from '@inertiajs/vue3';
    import axios from 'axios';
    import { computed, ref, watch } from 'vue';

    defineOptions({ layout: AuthenticatedLayout });

    let props = defineProps({
        merchant: {
            type: Object,
            required: true,
        },
        rules: {
            type: Array,
            default: () => [],
        },
        transactions: {
            type: Array,
            required: true,
        },
        transactionsTruncated: {
            type: Boolean,
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

    let nameForm = useForm({
        name: props.merchant.name,
    });

    let ruleForm = useForm({
        match_mode: 'contains',
        pattern: '',
    });

    let checkProcessing = ref(false);
    let checkResult = ref(null);
    let checkError = ref('');

    watch(
        () => props.filters.q,
        (value) => {
            search.value = value ?? '';
        },
    );

    watch(
        () => props.merchant.name,
        (value) => {
            nameForm.name = value;
        },
    );

    watch(
        () => [ruleForm.match_mode, ruleForm.pattern],
        () => {
            checkResult.value = null;
            checkError.value = '';
        },
    );

    let submitSearch = () => {
        router.get(
            `/merchants/${props.merchant.id}`,
            { q: search.value || undefined },
            {
                preserveState: true,
                replace: true,
            },
        );
    };

    let submitName = () => {
        nameForm.patch(`/merchants/${props.merchant.id}`);
    };

    let submitRule = () => {
        ruleForm.post(`/merchants/${props.merchant.id}/rules`, {
            onSuccess: () => {
                ruleForm.reset('pattern');
                checkResult.value = null;
                checkError.value = '';
            },
        });
    };

    let checkRule = async () => {
        if (checkProcessing.value || !ruleForm.pattern.trim()) {
            return;
        }

        checkProcessing.value = true;
        checkError.value = '';

        try {
            let { data } = await axios.post(
                `/merchants/${props.merchant.id}/rules/check`,
                {
                    match_mode: ruleForm.match_mode,
                    pattern: ruleForm.pattern,
                },
            );
            checkResult.value = data;
        } catch (error) {
            checkResult.value = null;
            checkError.value =
                error.response?.data?.errors?.pattern?.[0] ||
                error.response?.data?.errors?.match_mode?.[0] ||
                error.response?.data?.message ||
                'Could not check this rule.';
        } finally {
            checkProcessing.value = false;
        }
    };

    let toggleRule = (rule) => {
        router.patch(`/merchants/${props.merchant.id}/rules/${rule.id}`, {
            is_active: !rule.is_active,
        });
    };

    let deleteRule = (rule) => {
        if (!window.confirm('Delete this matching rule?')) {
            return;
        }

        router.delete(`/merchants/${props.merchant.id}/rules/${rule.id}`);
    };

    let addRuleFromTransaction = (transaction) => {
        if (!transaction.suggested_rule?.pattern) {
            return;
        }

        router.post(`/merchants/${props.merchant.id}/rules`, {
            match_mode: transaction.suggested_rule.match_mode,
            pattern: transaction.suggested_rule.pattern,
        });
    };

    let matchModeLabel = (mode) => {
        return (
            {
                contains: 'Description contains',
                extracted_name: 'Extracted name',
            }[mode] ?? mode
        );
    };

    let formatDate = (value) => value || '—';

    let formatMoney = (amount) => {
        return new Intl.NumberFormat('en-US', {
            style: 'currency',
            currency: 'USD',
        }).format(amount);
    };

    let statusStyles = {
        matched: {
            row: 'border-l-green-500',
            badge: 'bg-green-50 text-green-800',
            label: 'Matched',
        },
        unmatched: {
            row: 'border-l-amber-500',
            badge: 'bg-amber-50 text-amber-900',
            label: 'Unmatched',
        },
        partial: {
            row: 'border-l-sky-500',
            badge: 'bg-sky-50 text-sky-900',
            label: 'Partial',
        },
        ignored: {
            row: 'border-l-neutral-400',
            badge: 'bg-neutral-100 text-neutral-700',
            label: 'Ignored',
        },
    };

    let statusStyle = (status) =>
        statusStyles[status] ?? {
            row: 'border-l-neutral-300',
            badge: 'bg-neutral-100 text-neutral-700',
            label: status,
        };
</script>

<template>
    <div class="space-y-6">
        <div>
            <Link href="/orders" class="text-sm underline">Back to orders</Link>
            <h1 class="mt-2 text-2xl font-semibold">{{ merchant.name }}</h1>
            <p class="text-sm capitalize text-neutral-600">
                {{ merchant.type }} · Bank transactions
            </p>
        </div>

        <p
            v-if="flashSuccess"
            class="rounded border border-green-200 bg-green-50 px-3 py-2 text-sm text-green-800"
        >
            {{ flashSuccess }}
        </p>

        <form class="space-y-2 rounded border px-4 py-3" @submit.prevent="submitName">
            <label class="block text-sm font-medium" for="merchant-name"
                >Display name</label
            >
            <div class="flex flex-wrap gap-2">
                <input
                    id="merchant-name"
                    v-model="nameForm.name"
                    type="text"
                    class="min-w-64 flex-1 rounded border px-3 text-sm"
                    required
                />
                <button
                    type="submit"
                    class="btn rounded border px-4 text-sm"
                    :disabled="nameForm.processing"
                >
                    Save name
                </button>
            </div>
            <p
                v-if="nameForm.errors.name || nameForm.errors.normalized_name"
                class="text-sm text-red-600"
            >
                {{
                    nameForm.errors.name || nameForm.errors.normalized_name
                }}
            </p>
        </form>

        <div class="rounded border px-4 py-3 text-sm">
            <p class="font-medium">Posted-date coverage</p>
            <p class="text-neutral-600">
                {{ formatDate(merchant.min_posted_at) }} →
                {{ formatDate(merchant.max_posted_at) }}
                <template v-if="merchant.coverage_span_days !== null">
                    ({{ merchant.coverage_span_days }} day span)
                </template>
            </p>
            <p class="mt-1 text-neutral-600">
                {{ merchant.transaction_count }} transactions
            </p>
        </div>

        <section class="space-y-3">
            <div>
                <h2 class="text-lg font-semibold">Matching rules</h2>
                <p class="text-sm text-neutral-600">
                    Transactions whose bank memo matches a rule are assigned to
                    this merchant before fuzzy matching runs.
                </p>
            </div>

            <form
                class="flex flex-wrap items-end gap-2 rounded border px-4 py-3"
                @submit.prevent="submitRule"
            >
                <div>
                    <label class="mb-1 block text-sm" for="rule-mode"
                        >Match type</label
                    >
                    <select
                        id="rule-mode"
                        v-model="ruleForm.match_mode"
                        class="rounded border px-3 text-sm"
                    >
                        <option value="contains">Description contains</option>
                        <option value="extracted_name">Extracted name</option>
                    </select>
                </div>
                <div class="min-w-64 flex-1">
                    <label class="mb-1 block text-sm" for="rule-pattern"
                        >Pattern</label
                    >
                    <input
                        id="rule-pattern"
                        v-model="ruleForm.pattern"
                        type="text"
                        class="w-full rounded border px-3 text-sm"
                        required
                    />
                </div>
                <button
                    type="button"
                    class="btn inline-flex items-center gap-2 rounded border px-4 text-sm"
                    :disabled="checkProcessing || !ruleForm.pattern.trim()"
                    @click="checkRule"
                >
                    <span
                        v-if="checkProcessing"
                        class="inline-block size-3 animate-spin rounded-full border border-neutral-400 border-t-transparent"
                    />
                    {{ checkProcessing ? 'Checking…' : 'Check rule' }}
                </button>
                <button
                    type="submit"
                    class="btn rounded border px-4 text-sm"
                    :disabled="ruleForm.processing"
                >
                    Add rule
                </button>
                <p
                    v-if="ruleForm.errors.pattern || ruleForm.errors.match_mode"
                    class="w-full text-sm text-red-600"
                >
                    {{ ruleForm.errors.pattern || ruleForm.errors.match_mode }}
                </p>
                <p v-if="checkError" class="w-full text-sm text-red-600">
                    {{ checkError }}
                </p>
            </form>

            <div
                v-if="checkResult"
                class="space-y-3 rounded border px-4 py-3 text-sm"
            >
                <p
                    v-if="checkResult.duplicate_rule"
                    class="rounded border border-amber-200 bg-amber-50 px-3 py-2 text-amber-900"
                >
                    This pattern is already used by
                    {{ checkResult.duplicate_rule.merchant_name }}. Adding the
                    rule will be rejected.
                </p>
                <p class="text-neutral-600">
                    Saving this rule will not reassign transactions that already
                    have a merchant.
                </p>

                <div
                    class="rounded border px-3 py-2"
                    :class="
                        checkResult.conflict_count > 0
                            ? 'border-amber-200 bg-amber-50'
                            : 'border-neutral-200'
                    "
                >
                    <p class="font-medium">
                        {{ checkResult.conflict_count }}
                        {{
                            checkResult.conflict_count === 1
                                ? 'transaction assigned to another merchant would match'
                                : 'transactions assigned to other merchants would match'
                        }}
                    </p>
                    <ul
                        v-if="checkResult.conflicts.length"
                        class="mt-2 divide-y"
                    >
                        <li
                            v-for="transaction in checkResult.conflicts"
                            :key="transaction.id"
                            class="flex items-start justify-between gap-4 py-2"
                        >
                            <div class="min-w-0">
                                <p class="font-medium">
                                    {{ transaction.description }}
                                </p>
                                <p class="text-neutral-600">
                                    {{ formatDate(transaction.posted_at) }}
                                    <template v-if="transaction.merchant_name">
                                        · {{ transaction.merchant_name }}
                                    </template>
                                    <template v-if="transaction.account">
                                        · {{ transaction.account.name }}
                                    </template>
                                </p>
                            </div>
                            <p class="shrink-0 font-medium">
                                {{ formatMoney(transaction.amount) }}
                            </p>
                        </li>
                    </ul>
                    <p
                        v-if="checkResult.truncated?.conflicts"
                        class="mt-2 text-neutral-600"
                    >
                        Showing the newest 50 matching transactions.
                    </p>
                </div>

                <div class="rounded border border-neutral-200 px-3 py-2">
                    <p class="font-medium">
                        {{ checkResult.missed_count }}
                        of this merchant's transactions would not match
                    </p>
                    <ul
                        v-if="checkResult.missed.length"
                        class="mt-2 divide-y"
                    >
                        <li
                            v-for="transaction in checkResult.missed"
                            :key="transaction.id"
                            class="flex items-start justify-between gap-4 py-2"
                        >
                            <div class="min-w-0">
                                <p class="font-medium">
                                    {{ transaction.description }}
                                </p>
                                <p class="text-neutral-600">
                                    {{ formatDate(transaction.posted_at) }}
                                    <template v-if="transaction.account">
                                        · {{ transaction.account.name }}
                                    </template>
                                </p>
                            </div>
                            <p class="shrink-0 font-medium">
                                {{ formatMoney(transaction.amount) }}
                            </p>
                        </li>
                    </ul>
                    <p
                        v-if="checkResult.truncated?.missed"
                        class="mt-2 text-neutral-600"
                    >
                        Showing the newest 50 matching transactions.
                    </p>
                </div>

                <div class="rounded border border-neutral-200 px-3 py-2">
                    <p class="font-medium">
                        {{ checkResult.unassigned_count }}
                        {{
                            checkResult.unassigned_count === 1
                                ? 'unassigned transaction would match'
                                : 'unassigned transactions would match'
                        }}
                    </p>
                    <ul
                        v-if="checkResult.unassigned.length"
                        class="mt-2 divide-y"
                    >
                        <li
                            v-for="transaction in checkResult.unassigned"
                            :key="transaction.id"
                            class="flex items-start justify-between gap-4 py-2"
                        >
                            <div class="min-w-0">
                                <p class="font-medium">
                                    {{ transaction.description }}
                                </p>
                                <p class="text-neutral-600">
                                    {{ formatDate(transaction.posted_at) }}
                                    <template v-if="transaction.account">
                                        · {{ transaction.account.name }}
                                    </template>
                                </p>
                            </div>
                            <p class="shrink-0 font-medium">
                                {{ formatMoney(transaction.amount) }}
                            </p>
                        </li>
                    </ul>
                    <p
                        v-if="checkResult.truncated?.unassigned"
                        class="mt-2 text-neutral-600"
                    >
                        Showing the newest 50 matching transactions.
                    </p>
                </div>
            </div>

            <div v-if="rules.length === 0" class="text-sm text-neutral-600">
                No matching rules yet. Add one above, or from a transaction
                below.
            </div>

            <ul v-else class="divide-y rounded border text-sm">
                <li
                    v-for="rule in rules"
                    :key="rule.id"
                    class="flex flex-wrap items-start justify-between gap-3 px-4 py-3"
                >
                    <div>
                        <p class="font-medium">{{ rule.pattern }}</p>
                        <p class="text-neutral-600">
                            {{ matchModeLabel(rule.match_mode) }}
                        </p>
                        <p
                            class="text-xs"
                            :class="
                                rule.is_active
                                    ? 'text-green-700'
                                    : 'text-neutral-500'
                            "
                        >
                            {{ rule.is_active ? 'Active' : 'Disabled' }}
                        </p>
                    </div>
                    <div class="flex flex-wrap gap-2">
                        <button
                            type="button"
                            class="btn rounded border px-3"
                            @click="toggleRule(rule)"
                        >
                            {{ rule.is_active ? 'Disable' : 'Enable' }}
                        </button>
                        <button
                            type="button"
                            class="btn rounded border px-3 text-red-700"
                            @click="deleteRule(rule)"
                        >
                            Delete
                        </button>
                    </div>
                </li>
            </ul>
        </section>

        <form class="flex flex-wrap gap-2" @submit.prevent="submitSearch">
            <input
                v-model="search"
                type="search"
                placeholder="Search description or amount"
                class="min-w-64 flex-1 rounded border px-3 text-sm"
            />
            <button type="submit" class="btn rounded border px-4 text-sm">
                Search
            </button>
        </form>

        <div v-if="transactions.length === 0" class="text-sm text-neutral-600">
            No transactions match this search.
        </div>

        <ul v-else class="divide-y rounded border text-sm">
            <li
                v-for="transaction in transactions"
                :key="transaction.id"
                class="flex items-start justify-between gap-4 border-l-4 px-4 py-3"
                :class="statusStyle(transaction.status).row"
            >
                <div class="min-w-0">
                    <div class="flex flex-wrap items-center gap-2">
                        <p class="font-medium">{{ transaction.description }}</p>
                        <span
                            class="inline-flex items-center gap-1 rounded px-1.5 py-0.5 text-xs font-medium"
                            :class="statusStyle(transaction.status).badge"
                        >
                            <span
                                class="size-1.5 rounded-full"
                                :class="{
                                    'bg-green-600':
                                        transaction.status === 'matched',
                                    'bg-amber-500':
                                        transaction.status === 'unmatched',
                                    'bg-sky-500':
                                        transaction.status === 'partial',
                                    'bg-neutral-500':
                                        transaction.status === 'ignored' ||
                                        ![
                                            'matched',
                                            'unmatched',
                                            'partial',
                                        ].includes(transaction.status),
                                }"
                            />
                            {{ statusStyle(transaction.status).label }}
                        </span>
                    </div>
                    <p class="text-neutral-600">
                        {{ formatDate(transaction.posted_at) }}
                        <template v-if="transaction.account">
                            · {{ transaction.account.name }}
                        </template>
                        <template v-if="transaction.card_last_four">
                            · •••• {{ transaction.card_last_four }}
                        </template>
                    </p>
                    <button
                        v-if="transaction.suggested_rule?.pattern"
                        type="button"
                        class="mt-2 text-xs underline"
                        @click="addRuleFromTransaction(transaction)"
                    >
                        Add rule from this description
                    </button>
                </div>
                <p class="shrink-0 font-medium">
                    {{ formatMoney(transaction.amount) }}
                </p>
            </li>
        </ul>

        <p v-if="transactionsTruncated" class="text-sm text-neutral-600">
            Showing the newest 50 matching transactions.
        </p>
    </div>
</template>
