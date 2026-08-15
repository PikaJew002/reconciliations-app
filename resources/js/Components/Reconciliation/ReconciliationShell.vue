<script setup>
    import StickyToasts from '../StickyToasts.vue';
    import { Link, router, useForm, usePage } from '@inertiajs/vue3';
    import { computed, onMounted, onUnmounted, ref, watch } from 'vue';

    let props = defineProps({
        summary: {
            type: Object,
            required: true,
        },
        activeRun: {
            type: Object,
            default: null,
        },
        activeCategorizeRuns: {
            type: Array,
            default: () => [],
        },
        activeTab: {
            type: String,
            required: true,
        },
        reloadOnly: {
            type: Array,
            default: () => [],
        },
    });

    let page = usePage();
    let flashSuccess = computed(() => page.props.flash?.success);
    let flashError = computed(() => page.props.flash?.error);
    let runForm = useForm({});
    let isRunInProgress = computed(() =>
        ['pending', 'processing'].includes(props.activeRun?.status),
    );
    let categorizeRunsInProgress = computed(() =>
        props.activeCategorizeRuns.filter((run) =>
            ['pending', 'processing'].includes(run.status),
        ),
    );
    let hasCategorizeRunsInProgress = computed(
        () => categorizeRunsInProgress.value.length > 0,
    );
    let pollId = null;
    let categorizePollId = null;
    let toasts = ref([]);
    let nextToastId = 0;
    let toastTimers = new Map();
    let announcedCategorizeRunIds = new Set();
    let categorizeProgressToastId = null;

    let tabs = computed(() => {
        let items = [
            {
                id: 'unmatched-transactions',
                href: '/reconciliation/unmatched-transactions',
                label: 'Unmatched transactions',
                count: props.summary.unmatched_transactions,
            },
        ];

        let needsReviewCount = props.summary.needs_review ?? 0;

        if (needsReviewCount > 0) {
            items.push({
                id: 'needs-review',
                href: '/reconciliation/needs-review',
                label: 'Needs review',
                count: needsReviewCount,
            });
        }

        return items;
    });

    let showTabs = computed(() => tabs.value.length > 1);

    function runReloadKeys() {
        return [
            'summary',
            'activeRun',
            'activeCategorizeRuns',
            ...props.reloadOnly,
        ];
    }

    function categorizeReloadKeys() {
        return ['summary', 'activeCategorizeRuns', ...props.reloadOnly];
    }

    function runReconciliation() {
        runForm.post('/reconciliation/run', {
            preserveScroll: true,
            onSuccess: () => startPolling(),
        });
    }

    function startPolling() {
        if (pollId || !isRunInProgress.value) {
            return;
        }

        pollId = window.setInterval(() => {
            router.reload({
                only: runReloadKeys(),
                preserveScroll: true,
                onSuccess: () => {
                    if (!isRunInProgress.value && pollId) {
                        stopPolling();
                    }
                },
            });
        }, 2000);
    }

    function stopPolling() {
        if (pollId) {
            window.clearInterval(pollId);
            pollId = null;
        }
    }

    function startCategorizePolling() {
        if (categorizePollId || !hasCategorizeRunsInProgress.value) {
            return;
        }

        categorizePollId = window.setInterval(() => {
            router.reload({
                only: categorizeReloadKeys(),
                preserveScroll: true,
                onSuccess: () => {
                    if (!hasCategorizeRunsInProgress.value && categorizePollId) {
                        stopCategorizePolling();
                    }
                },
            });
        }, 2000);
    }

    function stopCategorizePolling() {
        if (categorizePollId) {
            window.clearInterval(categorizePollId);
            categorizePollId = null;
        }
    }

    function dismissToast(id) {
        toasts.value = toasts.value.filter((toast) => toast.id !== id);

        let timer = toastTimers.get(id);
        if (timer) {
            window.clearTimeout(timer);
            toastTimers.delete(id);
        }

        if (categorizeProgressToastId === id) {
            categorizeProgressToastId = null;
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

    function categorizeProgressMessage() {
        let count = categorizeRunsInProgress.value.length;

        return `Applying categorization ${
            count === 1 ? 'rule' : 'rules'
        } in the background (${count} active)… unmatched transactions will update automatically. You can keep categorizing.`;
    }

    function syncCategorizeProgressToast() {
        if (!hasCategorizeRunsInProgress.value) {
            if (categorizeProgressToastId !== null) {
                dismissToast(categorizeProgressToastId);
            }

            return;
        }

        let message = categorizeProgressMessage();

        if (categorizeProgressToastId === null) {
            categorizeProgressToastId = pushToast({
                type: 'warning',
                message,
                persistent: true,
            });
            return;
        }

        toasts.value = toasts.value.map((item) =>
            item.id === categorizeProgressToastId
                ? { ...item, message }
                : item,
        );
    }

    function completedCategorizeMessage(run) {
        let applied = run.metadata?.applied ?? 0;
        let ambiguous = run.metadata?.ambiguous ?? 0;
        let isIncome = run.metadata?.classification === 'income';
        let verb = isIncome ? 'Auto-classified' : 'Auto-categorized';
        let message = `Rule apply finished. ${verb} ${applied} transaction${
            applied === 1 ? '' : 's'
        }`;

        if (ambiguous > 0) {
            message += `; ${ambiguous} ambiguous`;
        }

        return `${message}.`;
    }

    function announceFinishedCategorizeRuns() {
        for (let run of props.activeCategorizeRuns) {
            if (announcedCategorizeRunIds.has(run.id)) {
                continue;
            }

            if (run.status === 'completed') {
                announcedCategorizeRunIds.add(run.id);
                pushToast({
                    type: 'success',
                    message: completedCategorizeMessage(run),
                    duration: 8000,
                });
                continue;
            }

            if (run.status === 'failed') {
                announcedCategorizeRunIds.add(run.id);
                pushToast({
                    type: 'error',
                    message: `Categorization rule apply failed${
                        run.error_message ? `: ${run.error_message}` : '.'
                    }`,
                    duration: 8000,
                });
            }
        }
    }

    onMounted(() => {
        for (let run of props.activeCategorizeRuns) {
            if (run.status === 'completed' || run.status === 'failed') {
                announcedCategorizeRunIds.add(run.id);
            }
        }

        syncCategorizeProgressToast();

        if (isRunInProgress.value) {
            startPolling();
        }

        if (hasCategorizeRunsInProgress.value) {
            startCategorizePolling();
        }
    });

    watch(
        flashSuccess,
        (message, previous) => {
            if (message && message !== previous) {
                pushToast({ type: 'success', message });
            }
        },
        { immediate: true },
    );

    watch(
        flashError,
        (message, previous) => {
            if (message && message !== previous) {
                pushToast({ type: 'error', message });
            }
        },
        { immediate: true },
    );

    watch(isRunInProgress, (inProgress) => {
        if (inProgress) {
            startPolling();
            return;
        }

        stopPolling();
    });

    watch(hasCategorizeRunsInProgress, (inProgress) => {
        syncCategorizeProgressToast();

        if (inProgress) {
            startCategorizePolling();
            return;
        }

        stopCategorizePolling();
    });

    watch(
        () => props.activeCategorizeRuns,
        () => {
            syncCategorizeProgressToast();
            announceFinishedCategorizeRuns();
        },
        { deep: true },
    );

    onUnmounted(() => {
        stopPolling();
        stopCategorizePolling();

        for (let timer of toastTimers.values()) {
            window.clearTimeout(timer);
        }
        toastTimers.clear();
    });
</script>

<template>
    <div class="space-y-8">
        <StickyToasts :toasts="toasts" @dismiss="dismissToast" />

        <div class="flex items-center justify-between gap-4">
            <div>
                <h1 class="text-2xl font-semibold">Reconciliation</h1>
                <p class="text-sm text-neutral-600">
                    Review matched and unmatched activity.
                </p>
            </div>

            <button
                type="button"
                class="rounded bg-neutral-900 px-4 py-2 text-sm text-white disabled:opacity-50"
                :disabled="runForm.processing || isRunInProgress"
                @click="runReconciliation"
            >
                {{
                    isRunInProgress || runForm.processing
                        ? 'Running…'
                        : 'Run reconciliation'
                }}
            </button>
        </div>

        <p
            v-if="isRunInProgress"
            class="rounded border border-amber-200 bg-amber-50 px-3 py-2 text-sm text-amber-900"
        >
            Reconciliation {{ activeRun.status }}… this page updates
            automatically.
        </p>

        <p
            v-else-if="activeRun?.status === 'completed'"
            class="rounded border border-green-200 bg-green-50 px-3 py-2 text-sm text-green-800"
        >
            Reconciliation finished. Confirmed
            {{ activeRun.metadata?.credit_card_payments_confirmed ?? 0 }} card
            payments and
            {{ activeRun.metadata?.transfers_confirmed ?? 0 }} transfers,
            suggested
            {{ activeRun.metadata?.credit_card_payments_suggested ?? 0 }} card
            payments and
            {{ activeRun.metadata?.transfers_suggested ?? 0 }} transfers,
            categorized
            {{ activeRun.metadata?.transactions_categorized ?? 0 }}
            transactions, matched
            {{ activeRun.metadata?.merchants_matched ?? 0 }} merchants,
            {{ activeRun.metadata?.planned_occurrences_matched ?? 0 }} planned
            occurrences,
            {{ activeRun.metadata?.transactions_matched ?? 0 }} order
            transactions, and
            {{ activeRun.metadata?.synthetic_matched ?? 0 }} synthetic bank
            spends.
        </p>

        <p
            v-else-if="activeRun?.status === 'failed'"
            class="rounded border border-red-200 bg-red-50 px-3 py-2 text-sm text-red-800"
        >
            Reconciliation failed{{
                activeRun.error_message ? `: ${activeRun.error_message}` : '.'
            }}
        </p>

        <p class="text-sm text-neutral-600">
            Re-runs merchant matching, Walmart order matching, planned
            paycheck/bill matching, and synthetic bank-spend reconciliation on
            data you have already imported.
        </p>

        <dl class="grid grid-cols-2 gap-3 text-sm sm:grid-cols-3">
            <div class="rounded border px-3 py-2">
                <dt class="text-neutral-600">Needs review</dt>
                <dd class="text-lg font-medium">
                    {{ summary.needs_review ?? 0 }}
                </dd>
            </div>
            <div class="rounded border px-3 py-2">
                <dt class="text-neutral-600">Unmatched orders</dt>
                <dd class="text-lg font-medium">
                    {{ summary.unmatched_orders }}
                </dd>
            </div>
            <div class="rounded border px-3 py-2">
                <dt class="text-neutral-600">Reconciled orders</dt>
                <dd class="text-lg font-medium">
                    {{ summary.reconciled_orders }}
                </dd>
            </div>
            <div class="rounded border px-3 py-2">
                <dt class="text-neutral-600">Matched pairs</dt>
                <dd class="text-lg font-medium">{{ summary.matched_pairs }}</dd>
            </div>
            <div class="rounded border px-3 py-2">
                <dt class="text-neutral-600">Unmatched transactions</dt>
                <dd class="text-lg font-medium">
                    {{ summary.unmatched_transactions }}
                </dd>
            </div>
            <div class="rounded border px-3 py-2">
                <dt class="text-neutral-600">Partial transactions</dt>
                <dd class="text-lg font-medium">
                    {{ summary.partial_transactions }}
                </dd>
            </div>
        </dl>

        <div class="space-y-4">
            <div v-if="showTabs" class="flex flex-wrap gap-2 border-b pb-2">
                <Link
                    v-for="tab in tabs"
                    :key="tab.id"
                    :href="tab.href"
                    class="rounded px-3 py-1.5 text-sm"
                    :class="
                        activeTab === tab.id
                            ? 'bg-neutral-900 text-white'
                            : 'text-neutral-700 hover:bg-neutral-100'
                    "
                >
                    {{ tab.label }}
                    <span class="ml-1 opacity-80">({{ tab.count }})</span>
                </Link>
            </div>

            <slot />
        </div>
    </div>
</template>
