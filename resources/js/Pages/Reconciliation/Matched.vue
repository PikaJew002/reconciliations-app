<script setup>
    import MatchedPairsList from '../../Components/Reconciliation/MatchedPairsList.vue';
    import ReconciliationShell from '../../Components/Reconciliation/ReconciliationShell.vue';
    import AuthenticatedLayout from '../../Layouts/AuthenticatedLayout.vue';

    defineOptions({ layout: AuthenticatedLayout });

    defineProps({
        summary: {
            type: Object,
            required: true,
        },
        matchedPairs: {
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

    const matchedReloadOnly = ['matchedPairs'];
</script>

<template>
    <ReconciliationShell
        :summary="summary"
        :active-run="activeRun"
        :active-categorize-runs="activeCategorizeRuns"
        active-tab="matched"
        :reload-only="matchedReloadOnly"
    >
        <MatchedPairsList :matched-pairs="matchedPairs" />
    </ReconciliationShell>
</template>
