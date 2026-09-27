<script setup>
    import { formatMoney } from '../Composables/useReconciliationFormatting.js';
    import {
        formatPostedDate,
        normalizeTransactions,
        pickerChips,
        pickerSections,
        transactionMatchesQuery,
        transactionMeta,
    } from '../Composables/useTransactionPicker.js';
    import { computed, nextTick, onBeforeUnmount, onMounted, ref, watch } from 'vue';

    let props = defineProps({
        modelValue: {
            type: [String, Number],
            default: '',
        },
        options: {
            type: Array,
            default: () => [],
        },
        preset: {
            type: String,
            required: true,
        },
        placeholder: {
            type: String,
            default: 'Select transaction',
        },
        merchants: {
            type: Array,
            default: () => [],
        },
        targetAmount: {
            type: [Number, String],
            default: null,
        },
        targetDate: {
            type: String,
            default: '',
        },
        targetCardLastFour: {
            type: String,
            default: '',
        },
        required: {
            type: Boolean,
            default: false,
        },
        clearable: {
            type: Boolean,
            default: false,
        },
    });

    let emit = defineEmits(['update:modelValue', 'change']);

    const pickerId = `transaction-picker-${Math.random().toString(36).slice(2, 9)}`;

    let open = ref(false);
    let query = ref('');
    let merchantFilter = ref('');
    let accountFilter = ref('');
    let nearAmount = ref(false);
    let nearDate = ref(false);
    let exactAmount = ref(false);
    let thisCard = ref(false);
    let side = ref('all');
    let highlightedIndex = ref(0);
    let triggerRef = ref(null);
    let panelRef = ref(null);
    let searchRef = ref(null);
    let panelStyle = ref({
        top: '0px',
        left: '0px',
        width: '320px',
    });

    let normalized = computed(() =>
        normalizeTransactions(props.options, props.merchants),
    );

    let selected = computed(
        () =>
            normalized.value.find(
                (transaction) =>
                    String(transaction.id) === String(props.modelValue ?? ''),
            ) ?? null,
    );

    let searched = computed(() =>
        normalized.value.filter((transaction) =>
            transactionMatchesQuery(transaction, query.value),
        ),
    );

    let chips = computed(() =>
        pickerChips({
            transactions: searched.value,
            preset: props.preset,
            targetAmount: props.targetAmount,
            targetDate: props.targetDate,
            targetCardLastFour: props.targetCardLastFour,
        }),
    );

    let sectionState = computed(() =>
        pickerSections({
            transactions: searched.value,
            allTransactions: normalized.value,
            preset: props.preset,
            filters: {
                merchant: merchantFilter.value,
                account: accountFilter.value,
                nearAmount: nearAmount.value,
                nearDate: nearDate.value,
                exactAmount: exactAmount.value,
                thisCard: thisCard.value,
                side: side.value,
            },
            targetAmount: props.targetAmount,
            targetDate: props.targetDate,
            targetCardLastFour: props.targetCardLastFour,
        }),
    );

    let sections = computed(() => sectionState.value.sections);
    let closestId = computed(() => sectionState.value.closestId);
    let flatItems = computed(() =>
        sections.value.flatMap((section) => section.items),
    );

    let requiredValue = computed(() => {
        if (props.modelValue == null) {
            return '';
        }

        return String(props.modelValue);
    });

    function sectionOffset(sectionIndex) {
        let offset = 0;

        for (let index = 0; index < sectionIndex; index += 1) {
            offset += sections.value[index].items.length;
        }

        return offset;
    }

    function meta(transaction) {
        return transactionMeta(transaction, {
            preset: props.preset,
            targetAmount: props.targetAmount,
            targetDate: props.targetDate,
        });
    }

    function chipIsActive(chip) {
        if (chip.group === 'merchant') {
            return merchantFilter.value === chip.id;
        }

        if (chip.group === 'account') {
            return accountFilter.value === chip.id;
        }

        if (chip.group === 'side') {
            return side.value === chip.id.slice('side:'.length);
        }

        if (chip.id === 'near-amount') {
            return nearAmount.value;
        }

        if (chip.id === 'near-date') {
            return nearDate.value;
        }

        if (chip.id === 'exact-amount') {
            return exactAmount.value;
        }

        return thisCard.value;
    }

    function toggleChip(chip) {
        if (chip.group === 'merchant') {
            merchantFilter.value =
                merchantFilter.value === chip.id ? '' : chip.id;

            return;
        }

        if (chip.group === 'account') {
            accountFilter.value = accountFilter.value === chip.id ? '' : chip.id;

            return;
        }

        if (chip.group === 'side') {
            side.value = chip.id.slice('side:'.length);

            return;
        }

        if (chip.id === 'near-amount') {
            nearAmount.value = !nearAmount.value;
        } else if (chip.id === 'near-date') {
            nearDate.value = !nearDate.value;
        } else if (chip.id === 'exact-amount') {
            exactAmount.value = !exactAmount.value;
        } else if (chip.id === 'this-card') {
            thisCard.value = !thisCard.value;
        }
    }

    function resetFilters() {
        query.value = '';
        merchantFilter.value = '';
        accountFilter.value = '';
        nearAmount.value = false;
        nearDate.value = false;
        exactAmount.value = false;
        thisCard.value = false;
        side.value = 'all';
        highlightedIndex.value = 0;
    }

    function positionPanel() {
        let trigger = triggerRef.value;

        if (!trigger) {
            return;
        }

        let rect = trigger.getBoundingClientRect();
        let width = Math.min(
            Math.max(rect.width, 22 * 16),
            window.innerWidth - 16,
        );
        let left = Math.min(Math.max(8, rect.left), window.innerWidth - width - 8);
        let panelHeight = panelRef.value?.offsetHeight ?? 320;
        let spaceBelow = window.innerHeight - rect.bottom;
        let spaceAbove = rect.top;
        let top = rect.bottom + 4;

        if (spaceBelow < panelHeight + 8 && spaceAbove > spaceBelow) {
            top = Math.max(8, rect.top - panelHeight - 4);
        }

        panelStyle.value = {
            top: `${top}px`,
            left: `${left}px`,
            width: `${width}px`,
        };
    }

    function openPicker() {
        open.value = true;
        window.dispatchEvent(
            new CustomEvent('transaction-picker-open', { detail: pickerId }),
        );
    }

    function closePicker(restoreFocus) {
        open.value = false;
        resetFilters();

        if (restoreFocus) {
            triggerRef.value?.focus();
        }
    }

    function togglePicker() {
        if (open.value) {
            closePicker(false);

            return;
        }

        openPicker();
    }

    function selectTransaction(transaction) {
        let id = String(transaction.id);
        emit('update:modelValue', id);
        emit('change', id);
        closePicker(false);
    }

    function clearSelection() {
        emit('update:modelValue', '');
        emit('change', '');
        closePicker(false);
    }

    function moveHighlight(delta) {
        let count = flatItems.value.length;

        if (count === 0) {
            return;
        }

        highlightedIndex.value =
            (highlightedIndex.value + delta + count) % count;

        nextTick(() => {
            document
                .getElementById(`${pickerId}-option-${highlightedIndex.value}`)
                ?.scrollIntoView({ block: 'nearest' });
        });
    }

    function selectHighlighted() {
        let transaction = flatItems.value[highlightedIndex.value];

        if (transaction) {
            selectTransaction(transaction);
        }
    }

    function onSearchKeydown(event) {
        if (event.key === 'ArrowDown') {
            event.preventDefault();
            moveHighlight(1);
        } else if (event.key === 'ArrowUp') {
            event.preventDefault();
            moveHighlight(-1);
        } else if (event.key === 'Enter') {
            event.preventDefault();
            selectHighlighted();
        } else if (event.key === 'Escape') {
            event.preventDefault();
            closePicker(true);
        }
    }

    function onTriggerKeydown(event) {
        if (event.key === 'ArrowDown') {
            event.preventDefault();
            openPicker();
        } else if (event.key === 'Escape' && open.value) {
            event.preventDefault();
            closePicker(false);
        }
    }

    function onOtherPicker(event) {
        if (event.detail !== pickerId && open.value) {
            closePicker(false);
        }
    }

    function onPointerDown(event) {
        let target = event.target;

        if (triggerRef.value?.contains(target) || panelRef.value?.contains(target)) {
            return;
        }

        closePicker(false);
    }

    function onViewportChange() {
        if (open.value) {
            positionPanel();
        }
    }

    watch(open, (isOpen) => {
        if (!isOpen) {
            document.removeEventListener('pointerdown', onPointerDown, true);
            window.removeEventListener('resize', onViewportChange);
            window.removeEventListener('scroll', onViewportChange, true);

            return;
        }

        document.addEventListener('pointerdown', onPointerDown, true);
        window.addEventListener('resize', onViewportChange);
        window.addEventListener('scroll', onViewportChange, true);
        nextTick(() => {
            let index = flatItems.value.findIndex(
                (transaction) =>
                    String(transaction.id) === String(props.modelValue ?? ''),
            );
            highlightedIndex.value = index >= 0 ? index : 0;
            positionPanel();
            searchRef.value?.focus();
            nextTick(() => {
                positionPanel();
                document
                    .getElementById(
                        `${pickerId}-option-${highlightedIndex.value}`,
                    )
                    ?.scrollIntoView({ block: 'nearest' });
            });
        });
    });

    watch(chips, (next) => {
        let ids = new Set(next.map((chip) => chip.id));

        if (merchantFilter.value && !ids.has(merchantFilter.value)) {
            merchantFilter.value = '';
        }

        if (accountFilter.value && !ids.has(accountFilter.value)) {
            accountFilter.value = '';
        }

        if (nearAmount.value && !ids.has('near-amount')) {
            nearAmount.value = false;
        }

        if (nearDate.value && !ids.has('near-date')) {
            nearDate.value = false;
        }

        if (exactAmount.value && !ids.has('exact-amount')) {
            exactAmount.value = false;
        }

        if (thisCard.value && !ids.has('this-card')) {
            thisCard.value = false;
        }

        if (side.value !== 'all' && !ids.has(`side:${side.value}`)) {
            side.value = 'all';
        }
    });

    watch(
        [query, merchantFilter, accountFilter, nearAmount, nearDate, exactAmount, thisCard, side],
        () => {
            highlightedIndex.value = 0;

            if (open.value) {
                nextTick(positionPanel);
            }
        },
    );

    onMounted(() => {
        window.addEventListener('transaction-picker-open', onOtherPicker);
    });

    onBeforeUnmount(() => {
        window.removeEventListener('transaction-picker-open', onOtherPicker);
        document.removeEventListener('pointerdown', onPointerDown, true);
        window.removeEventListener('resize', onViewportChange);
        window.removeEventListener('scroll', onViewportChange, true);
    });
</script>

<template>
    <div class="relative w-full min-w-0">
        <div class="relative">
            <button
                ref="triggerRef"
                type="button"
                class="flex w-full items-center gap-2 rounded border bg-white px-3 py-1.5 text-left text-sm focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-blue-500"
                :class="clearable && selected ? 'pr-8' : ''"
                role="combobox"
                :aria-expanded="open"
                aria-haspopup="listbox"
                :aria-controls="open ? `${pickerId}-listbox` : undefined"
                :aria-required="required || undefined"
                @click="togglePicker"
                @keydown="onTriggerKeydown"
            >
                <template v-if="selected">
                    <span class="w-14 shrink-0 tabular-nums text-neutral-500">
                        {{ formatPostedDate(selected.postedAt) }}
                    </span>
                    <span class="min-w-0 flex-1 truncate">
                        {{ selected.description || 'No description' }}
                    </span>
                    <span class="shrink-0 tabular-nums">
                        {{ formatMoney(selected.amount) }}
                    </span>
                </template>
                <span v-else class="min-w-0 flex-1 truncate text-neutral-500">
                    {{ placeholder }}
                </span>
                <span
                    v-if="!(clearable && selected)"
                    class="shrink-0 text-xs text-neutral-400"
                    aria-hidden="true"
                    >▾</span
                >
            </button>
            <button
                v-if="clearable && selected"
                type="button"
                class="absolute top-1/2 right-2 -translate-y-1/2 px-1 text-neutral-400 hover:text-neutral-700"
                aria-label="Clear transaction"
                @click="clearSelection"
            >
                ×
            </button>
        </div>
        <input
            v-if="required"
            class="pointer-events-none absolute inset-x-0 bottom-0 h-8 w-full opacity-0"
            tabindex="-1"
            :value="requiredValue"
            required
            aria-hidden="true"
        />

        <Teleport to="body">
            <div
                v-if="open"
                :id="`${pickerId}-listbox`"
                ref="panelRef"
                class="fixed z-40 flex max-h-[min(24rem,calc(100vh-1rem))] flex-col overflow-hidden rounded border bg-white shadow-lg"
                :style="panelStyle"
                role="listbox"
                :aria-label="placeholder"
            >
                <div class="border-b p-2">
                    <input
                        ref="searchRef"
                        v-model="query"
                        type="text"
                        class="w-full rounded border px-2 py-1 text-sm"
                        placeholder="Search date, amount, or description"
                        autocomplete="off"
                        spellcheck="false"
                        aria-label="Search transactions"
                        :aria-controls="`${pickerId}-listbox`"
                        :aria-activedescendant="
                            flatItems.length
                                ? `${pickerId}-option-${highlightedIndex}`
                                : undefined
                        "
                        @keydown="onSearchKeydown"
                    />
                </div>
                <div
                    v-if="chips.length"
                    class="flex flex-wrap gap-1 border-b px-2 py-2"
                >
                    <button
                        v-for="chip in chips"
                        :key="chip.id"
                        type="button"
                        class="rounded-full border px-2 py-0.5 text-xs"
                        :class="
                            chipIsActive(chip)
                                ? 'border-neutral-800 bg-neutral-800 text-white'
                                : 'text-neutral-600 hover:bg-neutral-50'
                        "
                        :aria-pressed="chipIsActive(chip)"
                        @click="toggleChip(chip)"
                    >
                        {{ chip.label }}
                    </button>
                </div>
                <div class="min-h-0 flex-1 overflow-y-auto">
                    <p
                        v-if="flatItems.length === 0"
                        class="px-3 py-6 text-center text-sm text-neutral-500"
                    >
                        No matching transactions.
                    </p>
                    <template
                        v-for="(section, sectionIndex) in sections"
                        :key="section.key"
                    >
                        <p
                            v-if="section.label"
                            class="px-3 pt-2 text-xs font-medium text-neutral-500"
                        >
                            {{ section.label }}
                        </p>
                        <button
                            v-for="(transaction, itemIndex) in section.items"
                            :id="`${pickerId}-option-${sectionOffset(sectionIndex) + itemIndex}`"
                            :key="transaction.id"
                            type="button"
                            role="option"
                            class="flex w-full flex-col gap-0.5 px-3 py-2 text-left text-sm"
                            :class="
                                sectionOffset(sectionIndex) + itemIndex ===
                                highlightedIndex
                                    ? 'bg-neutral-100'
                                    : 'hover:bg-neutral-50'
                            "
                            :aria-selected="
                                String(transaction.id) ===
                                String(modelValue ?? '')
                            "
                            @mouseenter="
                                highlightedIndex =
                                    sectionOffset(sectionIndex) + itemIndex
                            "
                            @click="selectTransaction(transaction)"
                        >
                            <span class="flex items-baseline gap-2">
                                <span
                                    class="w-14 shrink-0 tabular-nums text-neutral-500"
                                >
                                    {{ formatPostedDate(transaction.postedAt) }}
                                </span>
                                <span class="min-w-0 flex-1 truncate">
                                    {{
                                        transaction.description ||
                                        'No description'
                                    }}
                                </span>
                                <span
                                    v-if="
                                        closestId != null &&
                                        String(transaction.id) ===
                                            String(closestId)
                                    "
                                    class="shrink-0 rounded border px-1 text-[11px] leading-4 text-neutral-600"
                                >
                                    Closest
                                </span>
                                <span class="shrink-0 tabular-nums">
                                    {{ formatMoney(transaction.amount) }}
                                </span>
                            </span>
                            <span
                                v-if="meta(transaction)"
                                class="truncate pl-16 text-xs text-neutral-500"
                            >
                                {{ meta(transaction) }}
                            </span>
                        </button>
                    </template>
                </div>
            </div>
        </Teleport>
    </div>
</template>
