<script setup>
    import PaymentReviewOrders from '../../Components/Reconciliation/PaymentReviewOrders.vue';
    import ReimbursementGroups from '../../Components/Reconciliation/ReimbursementGroups.vue';
    import ReconciliationShell from '../../Components/Reconciliation/ReconciliationShell.vue';
    import SuggestedIncome from '../../Components/Reconciliation/SuggestedIncome.vue';
    import SuggestedTransfers from '../../Components/Reconciliation/SuggestedTransfers.vue';
    import UnbalancedOrders from '../../Components/Reconciliation/UnbalancedOrders.vue';
    import AuthenticatedLayout from '../../Layouts/AuthenticatedLayout.vue';

    defineOptions({ layout: AuthenticatedLayout });

    defineProps({
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
        suggestedIncome: {
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
        categories: {
            type: Array,
            default: () => [],
        },
        incomeMatchModes: {
            type: Array,
            default: () => [
                'exact_description_and_amount',
                'description',
                'once',
            ],
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
        'suggestedIncome',
        'openReimbursementGroups',
        'closedReimbursementGroups',
        'reimbursementEligibleTransactions',
        'categories',
    ];
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
            <SuggestedTransfers :suggested-transfers="suggestedTransfers" />
            <SuggestedIncome
                :suggested-income="suggestedIncome"
                :open-reimbursement-groups="openReimbursementGroups"
                :income-match-modes="incomeMatchModes"
            />
            <ReimbursementGroups
                :open-reimbursement-groups="openReimbursementGroups"
                :closed-reimbursement-groups="closedReimbursementGroups"
                :reimbursement-eligible-transactions="
                    reimbursementEligibleTransactions
                "
                :categories="categories"
            />
            <PaymentReviewOrders
                :payment-review-orders="paymentReviewOrders"
            />
            <UnbalancedOrders
                :unbalanced-orders="unbalancedOrders"
                :categories="categories"
            />
        </section>
    </ReconciliationShell>
</template>
