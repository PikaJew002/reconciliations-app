<script setup>
    import AuthenticatedLayout from '../../Layouts/AuthenticatedLayout.vue';
    import ReviewShell from '../../Components/Review/ReviewShell.vue';
    import { Link, router } from '@inertiajs/vue3';
    import { computed } from 'vue';

    defineOptions({ layout: AuthenticatedLayout });

    let props = defineProps({
        windows: {
            type: Array,
            default: () => [],
        },
        selected_occurrence_id: {
            type: Number,
            default: null,
        },
        leftover_origin: {
            type: Object,
            default: null,
        },
    });

    let selectedWindow = computed(() => {
        return (
            props.windows.find(
                (window) =>
                    window.paycheck.occurrence_id ===
                    props.selected_occurrence_id,
            ) ??
            props.windows.find((window) => window.is_current) ??
            props.windows[0] ??
            null
        );
    });

    let selectedIndex = computed(() => {
        if (!selectedWindow.value) {
            return -1;
        }

        return props.windows.findIndex(
            (window) =>
                window.paycheck.occurrence_id ===
                selectedWindow.value.paycheck.occurrence_id,
        );
    });

    let previousWindow = computed(() => {
        if (selectedIndex.value <= 0) {
            return null;
        }

        return props.windows[selectedIndex.value - 1];
    });

    let nextWindow = computed(() => {
        if (
            selectedIndex.value < 0 ||
            selectedIndex.value >= props.windows.length - 1
        ) {
            return null;
        }

        return props.windows[selectedIndex.value + 1];
    });

    let originLabel = computed(() => {
        if (!props.leftover_origin) {
            return null;
        }

        return (
            (props.leftover_origin.months ?? []).find(
                (option) => option.value === props.leftover_origin.month,
            )?.label ?? props.leftover_origin.month
        );
    });

    let creditCardAllocations = computed(() => {
        return (selectedWindow.value?.allocations ?? []).filter(
            (event) => event.kind === 'credit_card_payment',
        );
    });

    let savingsOutAllocations = computed(() => {
        return (selectedWindow.value?.allocations ?? []).filter(
            (event) => event.kind === 'savings_transfer' && event.amount > 0,
        );
    });

    let savingsInAllocations = computed(() => {
        return (selectedWindow.value?.allocations ?? []).filter(
            (event) => event.kind === 'savings_transfer' && event.amount < 0,
        );
    });

    let visitWindow = (window) => {
        if (!window) {
            return;
        }

        router.get(
            '/review',
            { occurrence: window.paycheck.occurrence_id },
            { preserveScroll: true },
        );
    };

    let formatMoney = (amount) => {
        if (amount === null || amount === undefined) {
            return '—';
        }

        return Number(amount).toLocaleString('en-US', {
            style: 'currency',
            currency: 'USD',
        });
    };

    let formatDelta = (amount) => {
        if (amount === null || amount === undefined) {
            return '—';
        }

        let formatted = formatMoney(Math.abs(amount));

        if (amount > 0) {
            return `+${formatted}`;
        }

        if (amount < 0) {
            return `−${formatted}`;
        }

        return formatted;
    };

    let formatDay = (date) => {
        if (!date) {
            return '—';
        }

        return new Date(`${date}T00:00:00`).toLocaleDateString('en-US', {
            month: 'short',
            day: 'numeric',
        });
    };

    let differenceClass = (value) => {
        if (value === null || value === undefined) {
            return 'text-neutral-600';
        }

        if (value < 0) {
            return 'text-red-700';
        }

        if (value > 0) {
            return 'text-emerald-700';
        }

        return 'text-ink';
    };
</script>

<template>
    <ReviewShell active-tab="leftover">
        <div class="space-y-6">
            <div>
                <h1 class="text-lg font-semibold">Leftover</h1>
                <p class="text-sm text-neutral-600">
                    What each paycheck contributed, spent, and carried into the
                    next check.
                </p>
                <p
                    v-if="leftover_origin && originLabel"
                    class="mt-2 text-sm text-neutral-600"
                >
                    Chain starts {{ originLabel }}, carry-over
                    {{ formatMoney(leftover_origin.carry_over ?? 0) }}.
                    <Link href="/plans" class="underline">Change on Plans</Link>
                </p>
            </div>

            <p v-if="windows.length === 0" class="text-sm text-neutral-600">
                Plan paychecks on
                <Link href="/plans" class="underline">Plans</Link>
                to see leftover from check to check.
            </p>

            <template v-else>
                <div class="flex gap-2 overflow-x-auto pb-1">
                    <Link
                        v-for="window in windows"
                        :key="window.paycheck.occurrence_id"
                        :href="`/review?occurrence=${window.paycheck.occurrence_id}`"
                        class="min-w-42 shrink-0 rounded border px-3 py-2"
                        :class="
                            window.is_selected
                                ? 'border-brand'
                                : 'border-neutral-200'
                        "
                    >
                        <p class="text-sm font-medium">
                            {{ window.paycheck.name }}
                            <span
                                v-if="window.is_current"
                                class="ml-1 text-xs font-normal text-neutral-500"
                            >
                                current
                            </span>
                        </p>
                        <p class="text-xs text-neutral-600">
                            {{ formatDay(window.paycheck.date) }}
                        </p>
                        <p
                            class="mt-1 text-sm font-semibold tabular-nums"
                            :class="
                                differenceClass(window.paycheck_remaining)
                            "
                        >
                            {{ formatDelta(window.paycheck_remaining) }}
                        </p>
                        <p
                            class="text-xs tabular-nums"
                            :class="differenceClass(window.remaining)"
                        >
                            {{ formatMoney(window.remaining) }}
                        </p>
                    </Link>
                </div>

                <div
                    v-if="selectedWindow"
                    class="space-y-4 rounded border px-4 py-3"
                >
                    <div class="flex flex-wrap items-start justify-between gap-3">
                        <div>
                            <p class="font-medium">
                                {{ selectedWindow.paycheck.name }}
                                <span
                                    v-if="selectedWindow.is_current"
                                    class="ml-1 text-xs font-normal text-neutral-500"
                                >
                                    current
                                </span>
                            </p>
                            <p class="text-sm text-neutral-600">
                                {{ formatDay(selectedWindow.paycheck.date) }}
                                <template v-if="selectedWindow.ends_before">
                                    → {{ formatDay(selectedWindow.ends_before) }}
                                </template>
                            </p>
                        </div>
                        <div class="text-right">
                            <p
                                class="text-2xl font-semibold tabular-nums"
                                :class="
                                    differenceClass(
                                        selectedWindow.paycheck_remaining,
                                    )
                                "
                            >
                                {{
                                    formatDelta(
                                        selectedWindow.paycheck_remaining,
                                    )
                                }}
                            </p>
                            <p
                                class="text-sm tabular-nums"
                                :class="
                                    differenceClass(selectedWindow.remaining)
                                "
                            >
                                {{ formatMoney(selectedWindow.remaining) }}
                                remaining
                            </p>
                        </div>
                    </div>

                    <div class="flex flex-wrap gap-2">
                        <button
                            type="button"
                            class="btn rounded border px-3 text-sm hover:bg-neutral-50 disabled:opacity-50"
                            :disabled="!previousWindow"
                            @click="visitWindow(previousWindow)"
                        >
                            Previous paycheck
                        </button>
                        <button
                            type="button"
                            class="btn rounded border px-3 text-sm hover:bg-neutral-50 disabled:opacity-50"
                            :disabled="!nextWindow"
                            @click="visitWindow(nextWindow)"
                        >
                            Next paycheck
                        </button>
                    </div>

                    <dl class="space-y-1 text-sm">
                        <div class="flex items-baseline justify-between gap-3">
                            <dt class="text-neutral-600">Brought forward</dt>
                            <dd
                                class="tabular-nums"
                                :class="
                                    differenceClass(
                                        selectedWindow.brought_forward,
                                    )
                                "
                            >
                                {{ formatMoney(selectedWindow.brought_forward) }}
                            </dd>
                        </div>
                        <div class="flex items-baseline justify-between gap-3">
                            <dt class="text-neutral-600">
                                {{ selectedWindow.paycheck.name }} after bills
                            </dt>
                            <dd class="tabular-nums">
                                {{ formatMoney(selectedWindow.planned_leftover) }}
                            </dd>
                        </div>
                        <div
                            v-if="(selectedWindow.credited ?? 0) > 0"
                            class="flex items-baseline justify-between gap-3"
                        >
                            <dt class="text-neutral-600">Other credits</dt>
                            <dd class="tabular-nums">
                                {{ formatMoney(selectedWindow.credited) }}
                            </dd>
                        </div>
                        <div class="flex items-baseline justify-between gap-3">
                            <dt class="text-neutral-600">Spent</dt>
                            <dd class="tabular-nums">
                                {{ formatMoney(selectedWindow.spent) }}
                            </dd>
                        </div>
                        <div
                            v-if="selectedWindow.credit_card_payments > 0"
                            class="flex items-baseline justify-between gap-3"
                        >
                            <dt class="text-neutral-600">Card payments</dt>
                            <dd class="tabular-nums">
                                {{
                                    formatMoney(
                                        selectedWindow.credit_card_payments,
                                    )
                                }}
                            </dd>
                        </div>
                        <div
                            v-if="selectedWindow.savings_transfers > 0"
                            class="flex items-baseline justify-between gap-3"
                        >
                            <dt class="text-neutral-600">To savings</dt>
                            <dd class="tabular-nums">
                                {{
                                    formatMoney(
                                        selectedWindow.savings_transfers,
                                    )
                                }}
                            </dd>
                        </div>
                        <div
                            v-else-if="selectedWindow.savings_transfers < 0"
                            class="flex items-baseline justify-between gap-3"
                        >
                            <dt class="text-neutral-600">From savings</dt>
                            <dd class="tabular-nums">
                                {{
                                    formatMoney(
                                        -selectedWindow.savings_transfers,
                                    )
                                }}
                            </dd>
                        </div>
                        <div
                            class="flex items-baseline justify-between gap-3 border-t pt-2 font-medium"
                        >
                            <dt>This paycheck</dt>
                            <dd
                                class="tabular-nums"
                                :class="
                                    differenceClass(
                                        selectedWindow.paycheck_remaining,
                                    )
                                "
                            >
                                {{
                                    formatDelta(
                                        selectedWindow.paycheck_remaining,
                                    )
                                }}
                            </dd>
                        </div>
                        <div class="flex items-baseline justify-between gap-3">
                            <dt>Remaining</dt>
                            <dd
                                class="tabular-nums"
                                :class="
                                    differenceClass(selectedWindow.remaining)
                                "
                            >
                                {{ formatMoney(selectedWindow.remaining) }}
                            </dd>
                        </div>
                    </dl>

                    <div class="grid gap-4 sm:grid-cols-2">
                        <div>
                            <p class="text-sm font-medium">Assigned bills</p>
                            <ul class="mt-2 space-y-1 text-sm">
                                <li
                                    v-if="selectedWindow.bills.length === 0"
                                    class="text-neutral-500"
                                >
                                    None assigned
                                </li>
                                <li
                                    v-for="bill in selectedWindow.bills"
                                    :key="bill.id"
                                    class="flex items-baseline justify-between gap-3 text-neutral-700"
                                >
                                    <span>
                                        {{ bill.name }}
                                        <span class="text-neutral-500">
                                            {{ formatDay(bill.expected_date) }}
                                        </span>
                                    </span>
                                    <span class="tabular-nums">
                                        {{ formatMoney(bill.amount) }}
                                    </span>
                                </li>
                            </ul>
                        </div>

                        <div>
                            <p class="text-sm font-medium">Unassigned bills</p>
                            <ul class="mt-2 space-y-1 text-sm">
                                <li
                                    v-if="
                                        selectedWindow.unassigned_bills
                                            .length === 0
                                    "
                                    class="text-neutral-500"
                                >
                                    None in this window
                                </li>
                                <li
                                    v-for="(bill, index) in selectedWindow.unassigned_bills"
                                    :key="`${bill.id ?? 'unplanned'}-${bill.date}-${index}`"
                                    class="flex items-baseline justify-between gap-3 text-neutral-700"
                                >
                                    <span>
                                        {{ bill.name }}
                                        <span class="text-neutral-500">
                                            {{ formatDay(bill.date) }}
                                        </span>
                                    </span>
                                    <span class="tabular-nums">
                                        {{ formatMoney(bill.amount) }}
                                    </span>
                                </li>
                            </ul>
                        </div>

                        <div>
                            <p class="text-sm font-medium">Other credits</p>
                            <ul class="mt-2 space-y-1 text-sm">
                                <li
                                    v-if="
                                        (selectedWindow.credits ?? [])
                                            .length === 0
                                    "
                                    class="text-neutral-500"
                                >
                                    None in this window
                                </li>
                                <li
                                    v-for="(event, index) in selectedWindow.credits ??
                                        []"
                                    :key="`credit-${event.date}-${index}`"
                                    class="flex items-baseline justify-between gap-3 text-neutral-700"
                                >
                                    <span>
                                        {{ event.name || 'Credit' }}
                                        <span class="text-neutral-500">
                                            {{ formatDay(event.date) }}
                                        </span>
                                    </span>
                                    <span class="tabular-nums">
                                        {{ formatMoney(event.amount) }}
                                    </span>
                                </li>
                            </ul>
                        </div>

                        <div>
                            <p class="text-sm font-medium">Card payments</p>
                            <ul class="mt-2 space-y-1 text-sm">
                                <li
                                    v-if="creditCardAllocations.length === 0"
                                    class="text-neutral-500"
                                >
                                    None in this window
                                </li>
                                <li
                                    v-for="(event, index) in creditCardAllocations"
                                    :key="`card-${event.date}-${index}`"
                                    class="flex items-baseline justify-between gap-3 text-neutral-700"
                                >
                                    <span>
                                        {{ event.name || 'Card payment' }}
                                        <span class="text-neutral-500">
                                            {{ formatDay(event.date) }}
                                        </span>
                                    </span>
                                    <span class="tabular-nums">
                                        {{ formatMoney(event.amount) }}
                                    </span>
                                </li>
                            </ul>
                        </div>

                        <div>
                            <p class="text-sm font-medium">Savings</p>
                            <ul class="mt-2 space-y-1 text-sm">
                                <li
                                    v-if="
                                        savingsOutAllocations.length === 0 &&
                                        savingsInAllocations.length === 0
                                    "
                                    class="text-neutral-500"
                                >
                                    None in this window
                                </li>
                                <li
                                    v-for="(event, index) in savingsOutAllocations"
                                    :key="`savings-out-${event.date}-${index}`"
                                    class="flex items-baseline justify-between gap-3 text-neutral-700"
                                >
                                    <span>
                                        To savings
                                        <span class="text-neutral-500">
                                            {{ formatDay(event.date) }}
                                        </span>
                                    </span>
                                    <span class="tabular-nums">
                                        {{ formatMoney(event.amount) }}
                                    </span>
                                </li>
                                <li
                                    v-for="(event, index) in savingsInAllocations"
                                    :key="`savings-in-${event.date}-${index}`"
                                    class="flex items-baseline justify-between gap-3 text-neutral-700"
                                >
                                    <span>
                                        From savings
                                        <span class="text-neutral-500">
                                            {{ formatDay(event.date) }}
                                        </span>
                                    </span>
                                    <span class="tabular-nums">
                                        {{ formatMoney(-event.amount) }}
                                    </span>
                                </li>
                            </ul>
                        </div>
                    </div>
                </div>
            </template>
        </div>
    </ReviewShell>
</template>
