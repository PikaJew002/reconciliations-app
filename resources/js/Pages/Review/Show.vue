<script setup>
    import ReviewLayout from '../../Layouts/ReviewLayout.vue';
    import ReviewShell from '../../Components/Review/ReviewShell.vue';
    import { router } from '@inertiajs/vue3';
    import { computed, onMounted, onUnmounted, ref, watch } from 'vue';

    defineOptions({ layout: ReviewLayout });

    let props = defineProps({
        week: {
            type: Object,
            required: true,
        },
        pass: {
            type: String,
            required: true,
        },
        week_spend: {
            type: Number,
            required: true,
        },
        slides: {
            type: Array,
            required: true,
        },
        expected_bills: {
            type: Object,
            default: null,
        },
        month: {
            type: String,
            required: true,
        },
        month_summary: {
            type: Object,
            required: true,
        },
        paycheck_leftover: {
            type: Object,
            default: null,
        },
        pace: {
            type: Object,
            required: true,
        },
        course_corrections: {
            type: Array,
            default: () => [],
        },
        categories: {
            type: Array,
            default: () => [],
        },
        act: {
            type: String,
            required: true,
        },
        item: {
            type: String,
            default: null,
        },
    });

    let act = ref(props.act);
    let item = ref(props.item);
    let gridOpen = ref(false);
    let lastChange = ref(null);
    let jumpArmed = ref(false);
    let jumpTimer = null;

    watch(
        () => props.act,
        (value) => {
            act.value = value;
        },
    );

    watch(
        () => props.item,
        (value) => {
            item.value = value;
        },
    );

    let currentIndex = computed(() => {
        if (!item.value) {
            return 0;
        }

        let index = props.slides.findIndex((slide) => slide.id === item.value);

        return index === -1 ? 0 : index;
    });

    let currentSlide = computed(() => {
        return props.slides[currentIndex.value] ?? null;
    });

    let nextSlide = computed(() => {
        return props.slides[currentIndex.value + 1] ?? null;
    });

    let gridCategories = computed(() => {
        let allowed = currentSlide.value?.allowed_kinds ?? [];

        return props.categories.filter((category) => allowed.includes(category.kind));
    });

    let paycheckRemaining = computed(() => {
        if (!props.paycheck_leftover) {
            return null;
        }

        return Number(props.paycheck_leftover.remaining);
    });

    let leftoverUntilLabel = computed(() => {
        let nextDate = props.paycheck_leftover?.next_paycheck?.date;

        if (!nextDate) {
            return 'Left to spend this paycheck';
        }

        return `Left to spend until ${formatDay(nextDate)}`;
    });

    let query = (overrides = {}) => {
        let next = {
            week: props.week.week,
            act: act.value,
            item: item.value,
            pass: props.pass,
            ...overrides,
        };

        Object.keys(next).forEach((key) => {
            if (next[key] === null || next[key] === undefined || next[key] === '') {
                delete next[key];
            }
        });

        return next;
    };

    let visit = (overrides = {}, replace = true) => {
        if (overrides.act !== undefined) {
            act.value = overrides.act;
        }

        if (overrides.item !== undefined) {
            item.value = overrides.item;
        }

        router.get('/review/sunday', query(overrides), {
            preserveState: true,
            preserveScroll: true,
            replace,
        });
    };

    let goOpen = () => {
        gridOpen.value = false;
        visit({ act: 'open' });
    };

    let goWalk = (slideId = props.slides[0]?.id ?? null) => {
        if (!slideId) {
            goClose();

            return;
        }

        gridOpen.value = false;
        visit({ act: 'walk', item: slideId });
    };

    let goClose = () => {
        gridOpen.value = false;
        visit({ act: 'close' });
    };

    let goNext = () => {
        if (act.value === 'open') {
            goWalk();

            return;
        }

        if (act.value === 'close') {
            return;
        }

        if (nextSlide.value) {
            goWalk(nextSlide.value.id);

            return;
        }

        goClose();
    };

    let goPrevious = () => {
        if (act.value === 'close') {
            if (props.slides.length > 0) {
                goWalk(props.slides[props.slides.length - 1].id);
            } else {
                goOpen();
            }

            return;
        }

        if (act.value === 'open') {
            return;
        }

        if (currentIndex.value > 0) {
            goWalk(props.slides[currentIndex.value - 1].id);

            return;
        }

        goOpen();
    };

    let setWeek = (week) => {
        if (!week) {
            return;
        }

        router.get(
            '/review/sunday',
            query({ week, act: 'open', item: undefined }),
            { preserveState: false, replace: false },
        );
    };

    let setPass = (pass) => {
        router.get(
            '/review/sunday',
            query({ pass, act: act.value === 'walk' ? 'open' : act.value }),
            { preserveState: false },
        );
    };

    let openGrid = () => {
        if (!currentSlide.value?.categorizable) {
            return;
        }

        gridOpen.value = true;
    };

    let closeGrid = () => {
        gridOpen.value = false;
    };

    let categorize = (categoryId) => {
        let slide = currentSlide.value;

        if (!slide?.categorizable) {
            return;
        }

        lastChange.value = {
            type: slide.kind,
            id: slide.source_id,
            previousCategoryId: slide.category?.id ?? null,
            item: slide.id,
        };

        gridOpen.value = false;

        router.post(
            '/review/sunday/categorize',
            {
                type: slide.kind,
                id: slide.source_id,
                category_id: categoryId,
                ...query({ act: 'walk', item: slide.id }),
            },
            {
                preserveState: true,
                preserveScroll: true,
                replace: true,
            },
        );
    };

    let undo = () => {
        if (!lastChange.value) {
            return;
        }

        let change = lastChange.value;
        lastChange.value = null;

        router.post(
            '/review/sunday/categorize',
            {
                type: change.type,
                id: change.id,
                category_id: change.previousCategoryId,
                ...query({ act: 'walk', item: change.item }),
            },
            {
                preserveState: true,
                preserveScroll: true,
                replace: true,
            },
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

    let formatDay = (date) => {
        if (!date) {
            return '—';
        }

        return new Date(`${date}T00:00:00`).toLocaleDateString('en-US', {
            weekday: 'short',
            month: 'short',
            day: 'numeric',
        });
    };

    let differenceClass = (value) => {
        if (value === null || value === undefined) {
            return 'text-mute';
        }

        if (value < 0) {
            return 'text-red-700';
        }

        if (value > 0) {
            return 'text-emerald-700';
        }

        return 'text-ink';
    };

    let remainingLabel = (value, positiveWord = 'remaining') => {
        if (value === null || value === undefined) {
            return '—';
        }

        if (value < 0) {
            return `${formatMoney(-value)} over`;
        }

        return `${formatMoney(value)} ${positiveWord}`;
    };

    let onKeydown = (event) => {
        if (event.metaKey || event.ctrlKey || event.altKey) {
            return;
        }

        if (gridOpen.value) {
            if (event.key === 'Escape') {
                event.preventDefault();
                closeGrid();
            }

            return;
        }

        if (jumpArmed.value) {
            event.preventDefault();
            jumpArmed.value = false;
            clearTimeout(jumpTimer);

            if (event.key === 'o') {
                goOpen();
            } else if (event.key === 'w') {
                goWalk();
            } else if (event.key === 'c') {
                goClose();
            }

            return;
        }

        if (event.key === 'g') {
            event.preventDefault();
            jumpArmed.value = true;
            jumpTimer = setTimeout(() => {
                jumpArmed.value = false;
            }, 800);

            return;
        }

        if (event.key === 'ArrowRight' || event.key === 'j' || event.key === ' ') {
            event.preventDefault();
            goNext();

            return;
        }

        if (event.key === 'ArrowLeft' || event.key === 'k') {
            event.preventDefault();
            goPrevious();

            return;
        }

        if (event.key === 'c') {
            event.preventDefault();
            openGrid();

            return;
        }

        if (event.key === 'u' && lastChange.value) {
            event.preventDefault();
            undo();

            return;
        }

        if (event.key === 'Escape' && act.value !== 'open') {
            event.preventDefault();
            goOpen();
        }
    };

    onMounted(() => {
        window.addEventListener('keydown', onKeydown);
    });

    onUnmounted(() => {
        window.removeEventListener('keydown', onKeydown);
        clearTimeout(jumpTimer);
    });
</script>

<template>
    <div class="flex min-h-screen flex-col px-10 py-8 lg:px-16 lg:py-10">
        <ReviewShell active-tab="sunday">
        <header class="flex flex-wrap items-baseline justify-between gap-4">
            <div>
                <p class="text-sm uppercase tracking-wide text-mute">Weekly review</p>
                <h1 class="text-3xl font-semibold lg:text-4xl">{{ week.label }}</h1>
            </div>
            <div class="flex flex-wrap items-center gap-3 text-lg">
                <button
                    type="button"
                    class="rounded px-2 py-1 text-mute hover:bg-brand-wash hover:text-ink"
                    :class="act === 'open' ? 'font-semibold text-brand' : ''"
                    @click="goOpen"
                >
                    Open
                </button>
                <button
                    type="button"
                    class="rounded px-2 py-1 text-mute hover:bg-brand-wash hover:text-ink"
                    :class="act === 'walk' ? 'font-semibold text-brand' : ''"
                    :disabled="slides.length === 0"
                    @click="goWalk()"
                >
                    Walk
                </button>
                <button
                    type="button"
                    class="rounded px-2 py-1 text-mute hover:bg-brand-wash hover:text-ink"
                    :class="act === 'close' ? 'font-semibold text-brand' : ''"
                    @click="goClose"
                >
                    Close
                </button>
            </div>
        </header>

        <main class="flex flex-1 flex-col justify-center py-10">
            <section v-if="act === 'open'" class="space-y-10">
                <div class="flex flex-wrap items-end justify-between gap-4">
                    <div class="flex gap-3">
                        <button
                            type="button"
                            class="rounded border px-4 py-2 text-lg hover:bg-brand-wash"
                            @click="setWeek(week.previous_week)"
                        >
                            Previous week
                        </button>
                        <button
                            v-if="week.next_week"
                            type="button"
                            class="rounded border px-4 py-2 text-lg hover:bg-brand-wash"
                            @click="setWeek(week.next_week)"
                        >
                            Next week
                        </button>
                    </div>
                    <button
                        v-if="expected_bills"
                        type="button"
                        class="rounded border px-4 py-2 text-lg hover:bg-brand-wash"
                        @click="
                            setPass(pass === 'all' ? 'default' : 'all')
                        "
                    >
                        {{
                            pass === 'all'
                                ? 'Hide expected bills'
                                : 'Include expected bills'
                        }}
                    </button>
                </div>

                <div class="grid gap-8 lg:grid-cols-2">
                    <div class="space-y-2">
                        <p class="text-xl text-mute">Last week discretionary</p>
                        <p class="text-6xl font-semibold tabular-nums lg:text-7xl">
                            {{ formatMoney(week_spend) }}
                        </p>
                        <p class="text-xl text-mute">
                            {{ formatMoney(pace.daily_rate) }} a day
                        </p>
                    </div>

                    <div class="space-y-2">
                        <p class="text-xl text-mute">{{ leftoverUntilLabel }}</p>
                        <p
                            v-if="paycheckRemaining !== null && paycheckRemaining >= 0"
                            class="text-6xl font-semibold tabular-nums lg:text-7xl"
                            :class="differenceClass(paycheckRemaining)"
                        >
                            {{ formatMoney(paycheckRemaining) }}
                        </p>
                        <p
                            v-else-if="paycheckRemaining !== null"
                            class="text-6xl font-semibold tabular-nums text-red-700 lg:text-7xl"
                        >
                            {{ formatMoney(-paycheckRemaining) }}
                        </p>
                        <p v-else class="text-3xl text-mute">No paycheck plan</p>
                        <p
                            v-if="paycheck_leftover?.days_remaining !== null && paycheck_leftover?.days_remaining !== undefined"
                            class="text-xl text-mute"
                        >
                            {{ paycheck_leftover.days_remaining }}
                            {{ paycheck_leftover.days_remaining === 1 ? 'day' : 'days' }}
                            left
                        </p>
                    </div>
                </div>

                <div class="grid gap-6 md:grid-cols-3">
                    <div class="rounded border px-6 py-5">
                        <p class="text-lg text-mute">{{ month_summary.label }} leftover income</p>
                        <p
                            class="mt-2 text-4xl font-semibold tabular-nums"
                            :class="differenceClass(month_summary.leftover_income)"
                        >
                            {{ formatMoney(month_summary.leftover_income) }}
                        </p>
                    </div>
                    <div class="rounded border px-6 py-5">
                        <p class="text-lg text-mute">Expenses vs budget</p>
                        <p
                            class="mt-2 text-4xl font-semibold tabular-nums"
                            :class="differenceClass(month_summary.vs_budget_difference)"
                        >
                            {{ remainingLabel(month_summary.vs_budget_difference) }}
                        </p>
                        <p class="mt-2 text-lg text-mute">
                            {{ formatMoney(month_summary.expenses) }} of
                            {{ formatMoney(month_summary.budget_allowed) }}
                        </p>
                    </div>
                    <div class="rounded border px-6 py-5">
                        <p class="text-lg text-mute">Expenses vs leftover</p>
                        <p
                            class="mt-2 text-4xl font-semibold tabular-nums"
                            :class="differenceClass(month_summary.vs_leftover_difference)"
                        >
                            {{ remainingLabel(month_summary.vs_leftover_difference) }}
                        </p>
                    </div>
                </div>

                <p class="text-2xl">
                    <template v-if="pace.paycheck_on_track === false">
                        At this week’s rate, leftover will not last until the next check.
                    </template>
                    <template v-else-if="pace.month_on_track_leftover === false">
                        At this week’s rate, expenses will pass leftover income this month.
                    </template>
                    <template v-else-if="pace.month_on_track_budget === false">
                        At this week’s rate, expenses will pass the monthly budget.
                    </template>
                    <template v-else>
                        At this week’s rate, leftover and the month stay on track.
                    </template>
                </p>

                <p class="text-xl text-mute">
                    Space or → to walk
                    {{ slides.length }}
                    {{ slides.length === 1 ? 'charge' : 'charges' }}.
                </p>
            </section>

            <section v-else-if="act === 'walk' && currentSlide" class="space-y-8">
                <div class="flex flex-wrap items-baseline justify-between gap-4 text-xl text-mute">
                    <p>
                        {{ currentIndex + 1 }} of {{ slides.length }}
                    </p>
                    <p>{{ formatDay(currentSlide.date) }}</p>
                </div>

                <div class="space-y-4">
                    <p
                        v-if="currentSlide.badge"
                        class="text-xl font-medium text-amber-800"
                    >
                        {{ currentSlide.badge }}
                    </p>
                    <p class="text-4xl font-semibold lg:text-5xl">
                        {{ currentSlide.name }}
                    </p>
                    <p class="text-7xl font-semibold tabular-nums lg:text-8xl">
                        {{ formatMoney(currentSlide.amount) }}
                    </p>
                </div>

                <ul
                    v-if="currentSlide.kind === 'expected_bills'"
                    class="max-w-3xl space-y-3 text-2xl"
                >
                    <li
                        v-for="(bill, index) in currentSlide.items"
                        :key="`${bill.name}-${index}`"
                        class="flex items-baseline justify-between gap-6"
                    >
                        <span>{{ bill.name }}</span>
                        <span class="tabular-nums">{{ formatMoney(bill.amount) }}</span>
                    </li>
                </ul>

                <div
                    v-else-if="currentSlide.kind === 'reimbursement'"
                    class="max-w-3xl space-y-5"
                >
                    <p class="text-2xl">
                        Spent
                        <span class="tabular-nums">{{
                            formatMoney(currentSlide.expense_total)
                        }}</span>
                        − back
                        <span class="tabular-nums">{{
                            formatMoney(currentSlide.reimbursement_total)
                        }}</span>
                        =
                        <span class="tabular-nums font-semibold">{{
                            formatMoney(currentSlide.net)
                        }}</span>
                        leftover
                    </p>
                    <p
                        v-if="currentSlide.category"
                        class="text-xl text-mute"
                    >
                        Booked to {{ currentSlide.category.name }}
                    </p>
                    <ul
                        v-if="currentSlide.items?.length"
                        class="space-y-2 text-2xl"
                    >
                        <li
                            v-for="(leg, index) in currentSlide.items"
                            :key="`${leg.role}-${leg.name}-${index}`"
                            class="flex items-baseline justify-between gap-6"
                        >
                            <span>
                                <span class="text-mute">{{
                                    leg.role === 'expense'
                                        ? 'Spent'
                                        : 'Back'
                                }}</span>
                                {{ leg.name }}
                            </span>
                            <span class="tabular-nums">{{
                                formatMoney(leg.amount)
                            }}</span>
                        </li>
                    </ul>
                </div>

                <button
                    v-if="currentSlide.categorizable"
                    type="button"
                    class="rounded border px-6 py-4 text-left text-2xl hover:bg-brand-wash"
                    :style="
                        currentSlide.category?.color
                            ? {
                                  borderLeftColor: currentSlide.category.color,
                                  borderLeftWidth: '8px',
                              }
                            : undefined
                    "
                    @click="openGrid"
                >
                    <span class="block text-lg text-mute">Category · c</span>
                    <span v-if="currentSlide.category">
                        {{ currentSlide.category.name }}
                    </span>
                    <span v-else class="text-amber-800">Uncategorized</span>
                </button>
                <p v-else-if="currentSlide.kind === 'order'" class="text-2xl text-mute">
                    Next steps through each line.
                </p>

                <p v-if="nextSlide" class="text-xl text-mute">
                    Next: {{ nextSlide.name }}
                </p>
                <p v-else class="text-xl text-mute">Next: close</p>
            </section>

            <section v-else-if="act === 'close'" class="space-y-10">
                <div class="grid gap-8 lg:grid-cols-2">
                    <div class="space-y-2">
                        <p class="text-xl text-mute">Last week discretionary</p>
                        <p class="text-6xl font-semibold tabular-nums lg:text-7xl">
                            {{ formatMoney(week_spend) }}
                        </p>
                    </div>
                    <div class="space-y-2">
                        <p class="text-xl text-mute">{{ leftoverUntilLabel }}</p>
                        <p
                            v-if="paycheckRemaining !== null && paycheckRemaining >= 0"
                            class="text-6xl font-semibold tabular-nums lg:text-7xl"
                            :class="differenceClass(paycheckRemaining)"
                        >
                            {{ formatMoney(paycheckRemaining) }}
                        </p>
                        <p
                            v-else-if="paycheckRemaining !== null"
                            class="text-6xl font-semibold tabular-nums text-red-700 lg:text-7xl"
                        >
                            {{ formatMoney(-paycheckRemaining) }}
                        </p>
                        <p v-else class="text-3xl text-mute">No paycheck plan</p>
                    </div>
                </div>

                <div class="grid gap-6 md:grid-cols-3">
                    <div class="rounded border px-6 py-5">
                        <p class="text-lg text-mute">{{ month_summary.label }} leftover income</p>
                        <p
                            class="mt-2 text-4xl font-semibold tabular-nums"
                            :class="differenceClass(month_summary.leftover_income)"
                        >
                            {{ formatMoney(month_summary.leftover_income) }}
                        </p>
                    </div>
                    <div class="rounded border px-6 py-5">
                        <p class="text-lg text-mute">Expenses vs budget</p>
                        <p
                            class="mt-2 text-4xl font-semibold tabular-nums"
                            :class="differenceClass(month_summary.vs_budget_difference)"
                        >
                            {{ remainingLabel(month_summary.vs_budget_difference) }}
                        </p>
                    </div>
                    <div class="rounded border px-6 py-5">
                        <p class="text-lg text-mute">Expenses vs leftover</p>
                        <p
                            class="mt-2 text-4xl font-semibold tabular-nums"
                            :class="differenceClass(month_summary.vs_leftover_difference)"
                        >
                            {{ remainingLabel(month_summary.vs_leftover_difference) }}
                        </p>
                    </div>
                </div>

                <div v-if="course_corrections.length > 0" class="space-y-4">
                    <p class="text-2xl font-semibold">What has to change</p>
                    <ul class="space-y-4">
                        <li
                            v-for="correction in course_corrections"
                            :key="correction.title"
                            class="rounded border px-6 py-5"
                        >
                            <p class="text-2xl font-medium">{{ correction.title }}</p>
                            <p class="mt-2 text-xl text-mute">{{ correction.detail }}</p>
                        </li>
                    </ul>
                </div>
                <p v-else class="text-2xl">
                    Nothing obvious has to change. Stay at this week’s pace.
                </p>
            </section>
        </main>

        <footer class="flex flex-wrap items-center justify-between gap-4 text-lg text-mute">
            <p>
                <template v-if="act === 'walk'">← / → to move · c to recategorize</template>
                <template v-else>← / → to move · g then o w c to jump</template>
                <template v-if="lastChange"> · u to undo</template>
            </p>
            <p>{{ week.label }}</p>
        </footer>
        </ReviewShell>

        <div
            v-if="gridOpen"
            class="fixed inset-0 z-10 flex flex-col bg-paper px-10 py-8 lg:px-16 lg:py-10"
        >
            <div class="flex items-baseline justify-between gap-4">
                <p class="text-3xl font-semibold">Choose a category</p>
                <button
                    type="button"
                    class="rounded border px-4 py-2 text-lg hover:bg-brand-wash"
                    @click="closeGrid"
                >
                    Esc
                </button>
            </div>
            <div class="mt-8 grid flex-1 grid-cols-2 gap-4 overflow-auto lg:grid-cols-3">
                <button
                    v-for="category in gridCategories"
                    :key="category.id"
                    type="button"
                    class="rounded border px-6 py-6 text-left text-2xl hover:bg-brand-wash"
                    :style="
                        category.color
                            ? {
                                  borderLeftColor: category.color,
                                  borderLeftWidth: '8px',
                                  backgroundColor: `${category.color}14`,
                              }
                            : undefined
                    "
                    @click="categorize(category.id)"
                >
                    {{ category.name }}
                </button>
            </div>
        </div>
    </div>
</template>
