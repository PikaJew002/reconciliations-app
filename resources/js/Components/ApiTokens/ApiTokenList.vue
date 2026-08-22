<script setup>
    import { router } from '@inertiajs/vue3';

    defineProps({
        tokens: {
            type: Array,
            required: true,
        },
    });

    let abilityLabel = (abilities) => {
        return (abilities ?? []).join(', ') || 'none';
    };

    let formatDate = (value) => {
        if (!value) {
            return 'Never';
        }

        return value.replace('T', ' ').replace(/\.\d+Z$/, ' UTC').replace(/Z$/, ' UTC');
    };

    let revoke = (token) => {
        if (!window.confirm(`Revoke token "${token.name}"?`)) {
            return;
        }

        router.delete(`/api-tokens/${token.id}`);
    };
</script>

<template>
    <section class="space-y-3">
        <h2 class="text-lg font-semibold">Existing tokens</h2>
        <p v-if="tokens.length === 0" class="text-sm text-neutral-600">
            No tokens yet.
        </p>
        <ul v-else class="divide-y rounded border">
            <li
                v-for="token in tokens"
                :key="token.id"
                class="flex flex-wrap items-center justify-between gap-3 px-4 py-3"
            >
                <div>
                    <p class="font-medium">{{ token.name }}</p>
                    <p class="text-sm text-neutral-600">
                        {{ abilityLabel(token.abilities) }}
                        · created {{ formatDate(token.created_at) }}
                        · last used {{ formatDate(token.last_used_at) }}
                        <template v-if="token.expires_at">
                            · expires {{ formatDate(token.expires_at) }}
                        </template>
                    </p>
                </div>
                <button
                    type="button"
                    class="btn rounded border px-3 text-sm hover:bg-brand-wash"
                    @click="revoke(token)"
                >
                    Revoke
                </button>
            </li>
        </ul>
    </section>
</template>
