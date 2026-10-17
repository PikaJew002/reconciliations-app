<script setup>
    import AuthenticatedLayout from '../../Layouts/AuthenticatedLayout.vue';
    import { loadPlaid } from '../../plaid';
    import { router } from '@inertiajs/vue3';
    import axios from 'axios';
    import { onMounted } from 'vue';

    defineOptions({ layout: AuthenticatedLayout });

    let props = defineProps({
        linkToken: {
            type: String,
            required: true,
        },
        mode: {
            type: String,
            default: 'create',
        },
        itemId: {
            type: String,
            default: null,
        },
    });

    const finish = (publicToken, metadata) => {
        if (props.mode === 'update' && props.itemId) {
            router.post(`/plaid/items/${props.itemId}/repaired`, {
                link_session_id: metadata?.link_session_id ?? null,
            });

            return;
        }

        router.post('/plaid/items', {
            public_token: publicToken,
            institution_id: metadata?.institution?.institution_id ?? null,
            link_session_id: metadata?.link_session_id ?? null,
        });
    };

    onMounted(async () => {
        let plaid = await loadPlaid();
        let handler = plaid.create({
            token: props.linkToken,
            receivedRedirectUri: window.location.href,
            onSuccess: finish,
            onExit(error, metadata) {
                axios.post('/plaid/link/exit', {
                    link_session_id: metadata?.link_session_id ?? null,
                    institution_id: metadata?.institution?.institution_id ?? null,
                    error_type: error?.error_type ?? null,
                    error_code: error?.error_code ?? null,
                    request_id: metadata?.request_id ?? null,
                }, {
                    withCredentials: true,
                    withXSRFToken: true,
                }).catch(() => {});
            },
        });

        handler.open();
    });
</script>

<template>
    <div class="space-y-2">
        <h1 class="text-2xl font-semibold">Finishing bank connection</h1>
        <p class="text-sm text-neutral-600">
            Plaid is bringing you back from your bank.
        </p>
    </div>
</template>
