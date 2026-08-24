<script setup>
    import {
        accountLabel,
        formatMoney,
    } from '../../Composables/useReconciliationFormatting.js';
    import { router } from '@inertiajs/vue3';
    import { computed, nextTick, reactive, ref, watch } from 'vue';

    let props = defineProps({
        unmatchedTransactions: {
            type: Array,
            required: true,
        },
        categories: {
            type: Array,
            default: () => [],
        },
        matchModes: {
            type: Array,
            default: () => [],
        },
        selectedTransactionIds: {
            type: Array,
            default: () => [],
        },
    });

    let emit = defineEmits(['toggle-selection']);

    let unmatchedTransactionFilter = ref('all');
    let unmatchedTransactionAccountFilter = ref('all');
    let categorizeForms = reactive({});
    let categorizingTransactionId = ref(null);
    let createCategoryOption = '__create__';
    let categoryFieldEls = {};

    let billCategories = computed(() =>
        props.categories.filter((category) => category.kind === 'bill'),
    );
    let expenseCategories = computed(() =>
        props.categories.filter((category) => category.kind === 'expense'),
    );
    let incomeCategories = computed(() =>
        props.categories.filter((category) => category.kind === 'income'),
    );

    let incomeMatchModes = [
        'exact_description_and_amount',
        'description',
        'once',
    ];

    function isWalmartTransaction(transaction) {
        let merchant = (transaction.merchant || '').toLowerCase();
        let description = (transaction.description || '').toLowerCase();

        return (
            merchant.includes('walmart') ||
            description.includes('walmart') ||
            description.includes('wal-mart') ||
            description.includes('wm supercenter')
        );
    }

    function isAmazonTransaction(transaction) {
        let merchant = (transaction.merchant || '').toLowerCase();
        let description = (transaction.description || '').toLowerCase();

        return (
            merchant.includes('amazon') ||
            merchant.includes('amzn') ||
            description.includes('amazon') ||
            description.includes('amzn')
        );
    }

    function isTransferTransaction(transaction) {
        return (transaction.description || '')
            .toLowerCase()
            .includes('transfer');
    }

    function unmatchedTransactionCategory(transaction) {
        if (isWalmartTransaction(transaction)) {
            return 'walmart';
        }

        if (isAmazonTransaction(transaction)) {
            return 'amazon';
        }

        if (!transaction.merchant && isTransferTransaction(transaction)) {
            return 'untagged-transfer';
        }

        if (!transaction.merchant) {
            return 'untagged-other';
        }

        return 'other';
    }

    function unmatchedTransactionTitle(transaction) {
        if (transaction.venmo_summary) {
            return transaction.venmo_summary;
        }

        if (transaction.merchant) {
            return transaction.merchant;
        }

        if (isTransferTransaction(transaction)) {
            return 'Untagged transaction (transfer)';
        }

        return 'Untagged transaction (Other)';
    }

    let unmatchedTransactionsForAccount = computed(() => {
        if (unmatchedTransactionAccountFilter.value === 'all') {
            return props.unmatchedTransactions;
        }

        return props.unmatchedTransactions.filter(
            (transaction) =>
                String(transaction.account_id) ===
                unmatchedTransactionAccountFilter.value,
        );
    });

    let unmatchedTransactionFilters = computed(() => {
        let transactions = unmatchedTransactionsForAccount.value;
        let counts = {
            all: transactions.length,
            walmart: 0,
            amazon: 0,
            'untagged-transfer': 0,
            'untagged-other': 0,
            credits: 0,
        };

        for (let transaction of transactions) {
            let category = unmatchedTransactionCategory(transaction);

            if (category in counts) {
                counts[category] += 1;
            }

            if (isCreditTransaction(transaction)) {
                counts.credits += 1;
            }
        }

        return [
            { id: 'all', label: 'All', count: counts.all },
            { id: 'walmart', label: 'Walmart', count: counts.walmart },
            { id: 'amazon', label: 'Amazon', count: counts.amazon },
            {
                id: 'untagged-transfer',
                label: 'Untagged (transfer)',
                count: counts['untagged-transfer'],
            },
            {
                id: 'untagged-other',
                label: 'Untagged (Other)',
                count: counts['untagged-other'],
            },
            { id: 'credits', label: 'Credits', count: counts.credits },
        ].filter((filter) => filter.id === 'all' || filter.count > 0);
    });

    watch(unmatchedTransactionAccountFilter, () => {
        unmatchedTransactionFilter.value = 'all';
    });

    watch(unmatchedTransactionFilters, (filters) => {
        if (
            !filters.some(
                (filter) => filter.id === unmatchedTransactionFilter.value,
            )
        ) {
            unmatchedTransactionFilter.value = 'all';
        }
    });

    let unmatchedTransactionAccountFilters = computed(() => {
        let countsByAccount = new Map();

        for (let transaction of props.unmatchedTransactions) {
            let accountId = transaction.account_id
                ? String(transaction.account_id)
                : 'unknown';
            let existing = countsByAccount.get(accountId);

            if (existing) {
                existing.count += 1;
                continue;
            }

            countsByAccount.set(accountId, {
                id: accountId,
                label: accountLabel(transaction),
                count: 1,
            });
        }

        let accountFilters = [...countsByAccount.values()].sort((a, b) =>
            a.label.localeCompare(b.label),
        );

        return [
            {
                id: 'all',
                label: 'All accounts',
                count: props.unmatchedTransactions.length,
            },
            ...accountFilters,
        ];
    });

    let filteredUnmatchedTransactions = computed(() => {
        let transactions = unmatchedTransactionsForAccount.value;

        if (unmatchedTransactionFilter.value === 'all') {
            return transactions;
        }

        if (unmatchedTransactionFilter.value === 'credits') {
            return transactions.filter((transaction) =>
                isCreditTransaction(transaction),
            );
        }

        return transactions.filter(
            (transaction) =>
                unmatchedTransactionCategory(transaction) ===
                unmatchedTransactionFilter.value,
        );
    });

    function isTransactionSelected(transactionId) {
        return props.selectedTransactionIds.includes(transactionId);
    }

    function matchModeLabel(mode) {
        return (
            {
                exact_description_and_amount: 'Exact description + amount',
                amount_and_merchant: 'Amount + merchant',
                merchant: 'Merchant only',
                description: 'Description only',
                check_and_amount: 'Check + amount',
                description_prefix_and_amount: 'Starts with + amount',
                once: 'This transaction only',
            }[mode] ?? mode
        );
    }

    function isCreditTransaction(transaction) {
        return Number(transaction.amount) > 0;
    }

    function categoriesForClassification(classification) {
        if (classification === 'bill') {
            return billCategories.value;
        }

        if (classification === 'income') {
            return incomeCategories.value;
        }

        return expenseCategories.value;
    }

    function isOneOffCategorizeOnly(transaction) {
        return Boolean(transaction?.one_off_categorize_only);
    }

    function matchModesForClassification(classification, transaction = null) {
        if (isOneOffCategorizeOnly(transaction)) {
            return ['once'];
        }

        if (classification === 'income') {
            return incomeMatchModes;
        }

        let billOnlyModes = [
            'check_and_amount',
            'description_prefix_and_amount',
        ];
        let merchantModes = ['merchant', 'amount_and_merchant'];
        let hasMerchant = Boolean(transaction?.merchant);

        return props.matchModes.filter((mode) => {
            if (billOnlyModes.includes(mode) && classification !== 'bill') {
                return false;
            }

            if (merchantModes.includes(mode) && !hasMerchant) {
                return false;
            }

            return true;
        });
    }

    function looksLikeConfirmationToken(token) {
        if (/^\d{4,}$/.test(token)) {
            return true;
        }

        if (/^(?=.*[a-z])(?=.*\d)[a-z0-9#*\-]{4,}$/i.test(token)) {
            return true;
        }

        return /^conf[a-z0-9]*$/i.test(token);
    }

    function suggestDescriptionPrefix(description) {
        let squished = String(description || '')
            .replace(/\s+/g, ' ')
            .trim();

        if (!squished) {
            return '';
        }

        let tokens = squished.split(' ');

        while (
            tokens.length > 1 &&
            looksLikeConfirmationToken(tokens[tokens.length - 1])
        ) {
            tokens.pop();
        }

        return tokens.join(' ');
    }

    function ensureCategorizeForm(transaction) {
        if (categorizeForms[transaction.id]) {
            return categorizeForms[transaction.id];
        }

        let defaultClassification = isCreditTransaction(transaction)
            ? 'income'
            : transaction.account_default_classification === 'bill'
              ? 'bill'
              : 'expense';

        categorizeForms[transaction.id] = {
            classification: defaultClassification,
            category_id: '',
            category_name: '',
            creating_category:
                categoriesForClassification(defaultClassification).length === 0,
            match_mode: 'once',
            normalized_pattern: '',
        };

        return categorizeForms[transaction.id];
    }

    function onCategorizeClassificationChange(transaction) {
        let form = ensureCategorizeForm(transaction);
        form.category_id = '';
        form.category_name = '';
        form.creating_category =
            categoriesForClassification(form.classification).length === 0;
        let availableModes = matchModesForClassification(
            form.classification,
            transaction,
        );
        if (!availableModes.includes(form.match_mode)) {
            form.match_mode = 'once';
        }
    }

    function hasCategoriesFor(transaction) {
        return (
            categoriesForClassification(
                ensureCategorizeForm(transaction).classification,
            ).length > 0
        );
    }

    function showsMatchPrefix(transaction) {
        return (
            ensureCategorizeForm(transaction).match_mode ===
            'description_prefix_and_amount'
        );
    }

    function setCategoryFieldEl(transactionId, el) {
        if (el) {
            categoryFieldEls[transactionId] = el;
            return;
        }

        delete categoryFieldEls[transactionId];
    }

    function focusCategoryField(transaction) {
        nextTick(() => {
            categoryFieldEls[transaction.id]?.focus();
        });
    }

    function onCategoryIdChange(transaction) {
        let form = ensureCategorizeForm(transaction);

        if (form.category_id !== createCategoryOption) {
            return;
        }

        form.category_id = '';
        form.category_name = '';
        form.creating_category = true;
        focusCategoryField(transaction);
    }

    function pickExistingCategory(transaction) {
        let form = ensureCategorizeForm(transaction);
        form.creating_category = false;
        form.category_name = '';
        form.category_id = '';
        focusCategoryField(transaction);
    }

    function categoryNamePlaceholder(classification) {
        if (classification === 'income') {
            return 'e.g. Paycheck';
        }

        if (classification === 'bill') {
            return 'e.g. Electric';
        }

        return 'e.g. Groceries';
    }

    function onCategorizeMatchModeChange(transaction) {
        let form = ensureCategorizeForm(transaction);

        if (
            form.match_mode === 'description_prefix_and_amount' &&
            !form.normalized_pattern
        ) {
            form.normalized_pattern = suggestDescriptionPrefix(
                transaction.description,
            );
        }
    }

    function canSubmitCategorize(transaction) {
        let form = ensureCategorizeForm(transaction);
        let hasCategory = form.creating_category
            ? Boolean(form.category_name.trim())
            : Boolean(form.category_id);

        return (
            hasCategory &&
            !(
                form.match_mode === 'description_prefix_and_amount' &&
                !form.normalized_pattern
            )
        );
    }

    function categorizeTransaction(transaction) {
        let form = ensureCategorizeForm(transaction);
        let categoryName = form.category_name.trim();

        if (form.creating_category) {
            if (!categoryName) {
                return;
            }
        } else if (!form.category_id) {
            return;
        }

        categorizingTransactionId.value = transaction.id;

        let payload = {
            classification: form.classification,
            match_mode: form.match_mode,
        };

        if (form.creating_category) {
            payload.category_name = categoryName;
        } else {
            payload.category_id = form.category_id;
        }

        if (form.match_mode === 'description_prefix_and_amount') {
            payload.normalized_pattern = form.normalized_pattern;
        }

        router.post(
            `/reconciliation/transactions/${transaction.id}/categorize`,
            payload,
            {
                preserveScroll: true,
                onFinish: () => {
                    categorizingTransactionId.value = null;
                },
            },
        );
    }
</script>

<template>
    <section class="space-y-3">
        <div v-if="unmatchedTransactions.length > 0" class="space-y-2">
            <div class="flex flex-wrap gap-2">
                <button
                    v-for="filter in unmatchedTransactionAccountFilters"
                    :key="`account-${filter.id}`"
                    type="button"
                    class="rounded px-3 py-1.5 text-sm"
                    :class="
                        unmatchedTransactionAccountFilter === filter.id
                            ? 'bg-brand hover:bg-brand-hover text-white'
                            : 'border text-neutral-700 hover:bg-neutral-100'
                    "
                    @click="unmatchedTransactionAccountFilter = filter.id"
                >
                    {{ filter.label }}
                    <span class="ml-1 opacity-80">({{ filter.count }})</span>
                </button>
            </div>
            <div class="flex flex-wrap gap-2">
                <button
                    v-for="filter in unmatchedTransactionFilters"
                    :key="filter.id"
                    type="button"
                    class="rounded px-3 py-1.5 text-sm"
                    :class="
                        unmatchedTransactionFilter === filter.id
                            ? 'bg-brand hover:bg-brand-hover text-white'
                            : 'border text-neutral-700 hover:bg-neutral-100'
                    "
                    @click="unmatchedTransactionFilter = filter.id"
                >
                    {{ filter.label }}
                    <span class="ml-1 opacity-80">({{ filter.count }})</span>
                </button>
            </div>
        </div>

        <slot />

        <p
            v-if="unmatchedTransactions.length === 0"
            class="text-sm text-neutral-600"
        >
            No unmatched transactions.
        </p>

        <p
            v-else-if="filteredUnmatchedTransactions.length === 0"
            class="text-sm text-neutral-600"
        >
            No unmatched transactions in this filter.
        </p>

        <ul v-else class="divide-y rounded border">
            <li
                v-for="transaction in filteredUnmatchedTransactions"
                :key="transaction.id"
                class="space-y-3 px-4 py-3 text-sm"
            >
                <div class="flex items-start justify-between gap-4">
                    <div class="flex items-start gap-3">
                        <input
                            type="checkbox"
                            class="mt-1"
                            :checked="isTransactionSelected(transaction.id)"
                            @change="emit('toggle-selection', transaction.id)"
                        />
                        <div>
                            <p class="font-medium">
                                {{ unmatchedTransactionTitle(transaction) }}
                            </p>
                            <p class="text-neutral-600">
                                {{
                                    transaction.transaction_date ||
                                    transaction.posted_at ||
                                    'No date'
                                }}
                                · {{ transaction.description }}
                                <span v-if="transaction.card_last_four">
                                    · card
                                    {{ transaction.card_last_four }}
                                </span>
                            </p>
                        </div>
                    </div>
                    <div class="text-right">
                        <p class="font-medium">
                            {{ formatMoney(transaction.amount) }}
                        </p>
                        <p class="text-neutral-600">
                            {{ transaction.status }}
                        </p>
                    </div>
                </div>

                <form
                    v-if="transaction.can_categorize"
                    class="grid items-end gap-2 rounded border bg-neutral-50 px-3 py-2 sm:grid-cols-2"
                    :class="
                        showsMatchPrefix(transaction)
                            ? 'lg:grid-cols-3'
                            : 'lg:grid-cols-[minmax(0,1fr)_minmax(0,1fr)_minmax(0,1fr)_auto]'
                    "
                    @submit.prevent="categorizeTransaction(transaction)"
                >
                    <label class="block min-w-0 space-y-1">
                        <span
                            class="flex h-5 items-center whitespace-nowrap text-neutral-600"
                            >Type</span
                        >
                        <select
                            v-model="
                                ensureCategorizeForm(transaction).classification
                            "
                            data-tour="categorize-type"
                            class="w-full rounded border px-2"
                            :disabled="isCreditTransaction(transaction)"
                            @change="
                                onCategorizeClassificationChange(transaction)
                            "
                        >
                            <template v-if="isCreditTransaction(transaction)">
                                <option value="income">Income</option>
                            </template>
                            <template v-else>
                                <option value="expense">Expense</option>
                                <option value="bill">Bill</option>
                            </template>
                        </select>
                    </label>
                    <label class="block min-w-0 space-y-1">
                        <span
                            class="flex h-5 items-center justify-between gap-2 text-neutral-600"
                        >
                            <span class="whitespace-nowrap">Category</span>
                            <button
                                v-if="
                                    ensureCategorizeForm(transaction)
                                        .creating_category &&
                                    hasCategoriesFor(transaction)
                                "
                                type="button"
                                class="shrink-0 text-xs text-brand underline"
                                @click="pickExistingCategory(transaction)"
                            >
                                Pick one
                            </button>
                        </span>
                        <div data-tour="categorize-category">
                            <select
                                v-if="
                                    !ensureCategorizeForm(transaction)
                                        .creating_category
                                "
                                :ref="
                                    (el) =>
                                        setCategoryFieldEl(transaction.id, el)
                                "
                                v-model="
                                    ensureCategorizeForm(transaction)
                                        .category_id
                                "
                                class="w-full rounded border px-2"
                                required
                                @change="onCategoryIdChange(transaction)"
                            >
                                <option disabled value="">Select</option>
                                <option
                                    v-for="category in categoriesForClassification(
                                        ensureCategorizeForm(transaction)
                                            .classification,
                                    )"
                                    :key="category.id"
                                    :value="category.id"
                                >
                                    {{ category.name }}
                                </option>
                                <option :value="createCategoryOption">
                                    Create a category…
                                </option>
                            </select>
                            <input
                                v-else
                                :ref="
                                    (el) =>
                                        setCategoryFieldEl(transaction.id, el)
                                "
                                v-model="
                                    ensureCategorizeForm(transaction)
                                        .category_name
                                "
                                type="text"
                                class="w-full rounded border px-2"
                                :placeholder="
                                    categoryNamePlaceholder(
                                        ensureCategorizeForm(transaction)
                                            .classification,
                                    )
                                "
                                autocomplete="off"
                                required
                            />
                        </div>
                    </label>
                    <label class="block min-w-0 space-y-1">
                        <span
                            class="flex h-5 items-center whitespace-nowrap text-neutral-600"
                            >Future match</span
                        >
                        <select
                            v-model="
                                ensureCategorizeForm(transaction).match_mode
                            "
                            data-tour="categorize-match"
                            class="w-full rounded border px-2"
                            :disabled="isOneOffCategorizeOnly(transaction)"
                            @change="onCategorizeMatchModeChange(transaction)"
                        >
                            <option
                                v-for="mode in matchModesForClassification(
                                    ensureCategorizeForm(transaction)
                                        .classification,
                                    transaction,
                                )"
                                :key="mode"
                                :value="mode"
                            >
                                {{ matchModeLabel(mode) }}
                            </option>
                        </select>
                    </label>
                    <label
                        v-if="showsMatchPrefix(transaction)"
                        class="block min-w-0 space-y-1 lg:col-span-2"
                    >
                        <span
                            class="flex h-5 items-center whitespace-nowrap text-neutral-600"
                            >Match prefix</span
                        >
                        <input
                            v-model="
                                ensureCategorizeForm(transaction)
                                    .normalized_pattern
                            "
                            type="text"
                            class="w-full rounded border px-2"
                            placeholder="e.g. toyota financial"
                            required
                        />
                    </label>
                    <div
                        class="space-y-1"
                        :class="
                            showsMatchPrefix(transaction)
                                ? 'sm:col-span-2 lg:col-span-1'
                                : ''
                        "
                    >
                        <span
                            class="hidden h-5"
                            :class="
                                showsMatchPrefix(transaction)
                                    ? 'lg:block'
                                    : 'sm:block'
                            "
                            aria-hidden="true"
                        />
                        <button
                            type="submit"
                            class="btn w-full rounded border border-brand bg-brand px-3 text-white hover:border-brand-hover hover:bg-brand-hover disabled:opacity-50"
                            :disabled="
                                categorizingTransactionId === transaction.id ||
                                !canSubmitCategorize(transaction)
                            "
                        >
                            {{
                                categorizingTransactionId === transaction.id
                                    ? 'Saving…'
                                    : 'Categorize'
                            }}
                        </button>
                    </div>
                    <p
                        v-if="isOneOffCategorizeOnly(transaction)"
                        class="text-xs text-neutral-500 sm:col-span-2 lg:col-span-4"
                    >
                        No imported order for this charge? Categorize it as a
                        one-off. This will not wait for an order or learn a
                        rule.
                    </p>
                    <p
                        v-else-if="showsMatchPrefix(transaction)"
                        class="text-xs text-neutral-500 sm:col-span-2 lg:col-span-3"
                    >
                        Matches other bills that start with this text and have
                        the same amount.
                    </p>
                </form>
                <p
                    v-else-if="transaction.supports_order_import"
                    class="text-xs text-neutral-600"
                >
                    Waiting for an imported order from this merchant.
                </p>
            </li>
        </ul>
    </section>
</template>
