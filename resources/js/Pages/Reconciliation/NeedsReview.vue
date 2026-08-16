<script setup>
    import PaymentReviewOrders from '../../Components/Reconciliation/PaymentReviewOrders.vue';
    import ReimbursementGroups from '../../Components/Reconciliation/ReimbursementGroups.vue';
    import ReconciliationShell from '../../Components/Reconciliation/ReconciliationShell.vue';
    import SuggestedTransfers from '../../Components/Reconciliation/SuggestedTransfers.vue';
    import UnbalancedOrders from '../../Components/Reconciliation/UnbalancedOrders.vue';
    import VenmoMatches from '../../Components/Reconciliation/VenmoMatches.vue';
    import AuthenticatedLayout from '../../Layouts/AuthenticatedLayout.vue';
    import { router } from '@inertiajs/vue3';
    import { watch } from 'vue';

    defineOptions({ layout: AuthenticatedLayout });

    let props = defineProps({
        summary: {
            type: Object,
            required: true,
        },
        unbalancedOrders: {
            type: Array,
            required: true,
        },
        paymentReviewOrders: {
            type: Array,
            required: true,
        },
        suggestedTransfers: {
            type: Array,
            default: () => [],
        },
        openReimbursementGroups: {
            type: Array,
            default: () => [],
        },
        closedReimbursementGroups: {
            type: Array,
            default: () => [],
        },
        reimbursementEligibleTransactions: {
            type: Array,
            default: () => [],
        },
        suggestedVenmoMatches: {
            type: Array,
            default: () => [],
        },
        unmatchedVenmoActivities: {
            type: Array,
            default: () => [],
        },
        categories: {
            type: Array,
            default: () => [],
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

    let needsReviewReloadOnly = [
        'unbalancedOrders',
        'paymentReviewOrders',
        'suggestedTransfers',
        'openReimbursementGroups',
        'closedReimbursementGroups',
        'reimbursementEligibleTransactions',
        'suggestedVenmoMatches',
        'unmatchedVenmoActivities',
        'categories',
    ];

    watch(
        () => props.summary.needs_review ?? 0,
        (count) => {
            if (count === 0) {
                router.visit('/reconciliation/unmatched-transactions', {
                    replace: true,
                });
            }
        },
        { immediate: true },
    );
</script>

<template>
    <ReconciliationShell
        :summary="summary"
        :active-run="activeRun"
        :active-categorize-runs="activeCategorizeRuns"
        active-tab="needs-review"
        :reload-only="needsReviewReloadOnly"
    >
        <section class="space-y-8">
            <SuggestedTransfers
                v-if="suggestedTransfers.length > 0"
                :suggested-transfers="suggestedTransfers"
            />
            <VenmoMatches
                v-if="
                    suggestedVenmoMatches.length > 0 ||
                    unmatchedVenmoActivities.length > 0
                "
                :suggested-venmo-matches="suggestedVenmoMatches"
                :unmatched-venmo-activities="unmatchedVenmoActivities"
            />
            <ReimbursementGroups
                v-if="
                    openReimbursementGroups.length > 0 ||
                    closedReimbursementGroups.length > 0
                "
                :open-reimbursement-groups="openReimbursementGroups"
                :closed-reimbursement-groups="closedReimbursementGroups"
                :reimbursement-eligible-transactions="
                    reimbursementEligibleTransactions
                "
                :categories="categories"
            />
            <PaymentReviewOrders
                v-if="paymentReviewOrders.length > 0"
                :payment-review-orders="paymentReviewOrders"
            />
            <UnbalancedOrders
                v-if="unbalancedOrders.length > 0"
                :unbalanced-orders="unbalancedOrders"
                :categories="categories"
            />
        </section>
    </ReconciliationShell>
</template>
