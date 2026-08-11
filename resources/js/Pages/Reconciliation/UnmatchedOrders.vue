<script setup>
    import ReconciliationShell from '../../Components/Reconciliation/ReconciliationShell.vue';
    import UnmatchedOrdersList from '../../Components/Reconciliation/UnmatchedOrdersList.vue';
    import AuthenticatedLayout from '../../Layouts/AuthenticatedLayout.vue';

    defineOptions({ layout: AuthenticatedLayout });

    defineProps({
        summary: {
            type: Object,
            required: true,
        },
        unmatchedOrders: {
            type: Array,
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
    });

    const unmatchedOrdersReloadOnly = ['unmatchedOrders'];
</script>

<template>
    <ReconciliationShell
        :summary="summary"
        :active-run="activeRun"
        :active-categorize-runs="activeCategorizeRuns"
        active-tab="unmatched-orders"
        :reload-only="unmatchedOrdersReloadOnly"
    >
        <UnmatchedOrdersList :unmatched-orders="unmatchedOrders" />
    </ReconciliationShell>
</template>
