<script setup>
    import { computed } from 'vue';
    import {
        randomCategoryColor,
        stripColorHash,
    } from '../Composables/categoryColor.js';

    let props = defineProps({
        modelValue: {
            type: String,
            default: '',
        },
        id: {
            type: String,
            default: 'color',
        },
    });

    let emit = defineEmits(['update:modelValue']);

    let HEX_RE = /^[0-9A-Fa-f]{6}$/;
    let generatedColor = randomCategoryColor();

    let hexDigits = computed(() => stripColorHash(props.modelValue));

    let validColor = computed(() =>
        HEX_RE.test(hexDigits.value) ? `#${hexDigits.value}` : null,
    );

    let pickerValue = computed(() => validColor.value ?? generatedColor);

    let generateColor = () => {
        generatedColor = randomCategoryColor();
        emit('update:modelValue', generatedColor);
    };

    let onPickerInput = (event) => {
        emit('update:modelValue', event.target.value);
    };

    let onTextInput = (event) => {
        let hex = stripColorHash(event.target.value)
            .replace(/[^0-9A-Fa-f]/g, '')
            .slice(0, 6);

        emit('update:modelValue', hex ? `#${hex}` : '');
    };

    if (!validColor.value) {
        emit('update:modelValue', generatedColor);
    }
</script>

<template>
    <div class="flex flex-wrap items-center gap-3">
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
            class="flex h-10 min-w-[calc(7ch+3rem)] flex-1 items-stretch overflow-hidden rounded border bg-white focus-within:outline-2 focus-within:outline-offset-0 focus-within:outline-blue-500"
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
                size="6"
                maxlength="7"
                spellcheck="false"
                autocomplete="off"
                class="h-full min-w-[6ch] flex-1 border-0 px-3 font-mono outline-none"
                @input="onTextInput"
            />
        </div>
        <button
            type="button"
            class="btn shrink-0 rounded border px-3 text-sm text-neutral-700 hover:bg-neutral-100"
            @click="generateColor"
        >
            Generate a color
        </button>
    </div>
</template>
