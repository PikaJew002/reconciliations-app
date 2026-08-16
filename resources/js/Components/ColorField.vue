<script setup>
    import { computed } from 'vue';
    import { stripColorHash } from '../Composables/categoryColor.js';

    let props = defineProps({
        modelValue: {
            type: String,
            default: '',
        },
        id: {
            type: String,
            default: 'color',
        },
        placeholder: {
            type: String,
            default: '336699',
        },
    });

    let emit = defineEmits(['update:modelValue']);

    let HEX_RE = /^[0-9A-Fa-f]{6}$/;

    let hexDigits = computed(() => stripColorHash(props.modelValue));

    let validColor = computed(() =>
        HEX_RE.test(hexDigits.value) ? `#${hexDigits.value}` : null,
    );

    let pickerValue = computed(() => validColor.value ?? '#336699');

    let onPickerInput = (event) => {
        emit('update:modelValue', event.target.value);
    };

    let onTextInput = (event) => {
        let hex = stripColorHash(event.target.value)
            .replace(/[^0-9A-Fa-f]/g, '')
            .slice(0, 6);

        emit('update:modelValue', hex ? `#${hex}` : '');
    };
</script>

<template>
    <div class="flex items-center gap-3">
        <div
            class="relative h-10 w-10 shrink-0 overflow-hidden rounded border"
            :class="
                validColor
                    ? 'border-neutral-300'
                    : 'border-dashed border-neutral-300 bg-neutral-50'
            "
            :style="
                validColor ? { backgroundColor: validColor } : undefined
            "
        >
            <input
                type="color"
                :value="pickerValue"
                class="absolute inset-0 h-full w-full cursor-pointer opacity-0"
                aria-label="Pick a color"
                @input="onPickerInput"
            />
        </div>
        <div
            class="flex h-10 min-w-0 flex-1 items-stretch overflow-hidden rounded border bg-white focus-within:outline focus-within:outline-2 focus-within:outline-offset-0 focus-within:outline-blue-500"
        >
            <span
                class="flex items-center border-r bg-neutral-50 px-3 font-mono text-neutral-500"
                aria-hidden="true"
                >#</span
            >
            <input
                :id="id"
                :value="hexDigits"
                type="text"
                :placeholder="placeholder"
                maxlength="7"
                spellcheck="false"
                autocomplete="off"
                class="h-full w-full border-0 px-3 font-mono outline-none"
                @input="onTextInput"
            />
        </div>
    </div>
</template>
