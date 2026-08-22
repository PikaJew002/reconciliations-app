<script setup>
    import { ref } from 'vue';

    let props = defineProps({
        token: {
            type: String,
            default: null,
        },
    });

    let copiedToken = ref(false);

    let copyToken = async () => {
        await navigator.clipboard.writeText(String(props.token));
        copiedToken.value = true;
    };
</script>

<template>
    <div
        v-if="token"
        class="space-y-2 rounded border border-amber-200 bg-amber-50 px-4 py-3"
    >
        <p class="text-sm font-medium text-amber-900">
            New token — copy it now
        </p>
        <div class="flex flex-wrap items-start gap-2">
            <code
                class="min-w-0 flex-1 break-all rounded border bg-white px-3 py-2 text-sm"
            >{{ token }}</code>
            <button
                type="button"
                class="btn rounded border px-3 text-sm hover:bg-brand-wash"
                @click="copyToken"
            >
                {{ copiedToken ? 'Copied' : 'Copy' }}
            </button>
        </div>
    </div>
</template>
