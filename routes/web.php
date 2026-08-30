<?php

use App\Http\Controllers\Accounts\AccountController;
use App\Http\Controllers\Accounts\AccountImportController;
use App\Http\Controllers\ApiTokens\ApiTokenController;
use App\Http\Controllers\Auth\AuthenticatedSessionController;
use App\Http\Controllers\Auth\ExtensionAuthController;
use App\Http\Controllers\Auth\RegisteredUserController;
use App\Http\Controllers\Budgets\BudgetController;
use App\Http\Controllers\Budgets\BudgetYearController;
use App\Http\Controllers\Categories\CategorizationRuleController;
use App\Http\Controllers\Categories\CategoryController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\Imports\ImportBatchController;
use App\Http\Controllers\Merchants\MerchantController;
use App\Http\Controllers\Merchants\MerchantMatchingRuleController;
use App\Http\Controllers\Onboarding\OnboardingController;
use App\Http\Controllers\Orders\OrderCategorizationController;
use App\Http\Controllers\Orders\OrderController;
use App\Http\Controllers\Orders\OrderItemCategorizationController;
use App\Http\Controllers\Orders\RetailerImportController;
use App\Http\Controllers\Plans\PlannedOccurrenceController;
use App\Http\Controllers\Plans\PlannedTemplateController;
use App\Http\Controllers\Products\ProductController;
use App\Http\Controllers\Reconciliation\OrderComponentCategoryController;
use App\Http\Controllers\Reconciliation\OrderComponentController;
use App\Http\Controllers\Reconciliation\OrderItemController;
use App\Http\Controllers\Reconciliation\OrderPaymentResolutionController;
use App\Http\Controllers\Reconciliation\ReconciliationController;
use App\Http\Controllers\Reconciliation\ReimbursementGroupController;
use App\Http\Controllers\Reconciliation\TransactionCategorizationController;
use App\Http\Controllers\Reconciliation\TransferLinkController;
use App\Http\Controllers\Reconciliation\VenmoMatchController;
use App\Http\Controllers\Rules\IncomeClassificationRuleController;
use App\Http\Controllers\Rules\RuleController;
use App\Http\Controllers\Venmo\VenmoImportController;
use Illuminate\Support\Facades\Route;

Route::get('/extension/auth', [ExtensionAuthController::class, 'start'])
    ->name('extension.auth');

Route::middleware('guest')->group(function () {
    Route::get('/register', [RegisteredUserController::class, 'create'])->name('register');
    Route::post('/register', [RegisteredUserController::class, 'store']);

    Route::get('/login', [AuthenticatedSessionController::class, 'create'])->name('login');
    Route::post('/login', [AuthenticatedSessionController::class, 'store']);
});

Route::middleware('auth')->group(function () {

    Route::get('/extension/auth/callback', [ExtensionAuthController::class, 'callback'])
        ->name('extension.auth.callback');

    Route::post('/logout', [AuthenticatedSessionController::class, 'destroy'])->name('logout');

    Route::get('/', [DashboardController::class, 'index'])->name('dashboard');

    Route::get('/api-tokens/pending-spend', [ApiTokenController::class, 'pendingSpend'])
        ->name('api-tokens.pending-spend');
    Route::post('/api-tokens/pending-spend', [ApiTokenController::class, 'storePendingSpend'])
        ->name('api-tokens.pending-spend.store');
    Route::get('/api-tokens/leftover-reporting', [ApiTokenController::class, 'leftoverReporting'])
        ->name('api-tokens.leftover-reporting');
    Route::post('/api-tokens/leftover-reporting', [ApiTokenController::class, 'storeLeftoverReporting'])
        ->name('api-tokens.leftover-reporting.store');
    Route::get('/api-tokens/retailer-scraper', [ApiTokenController::class, 'retailerScraper'])
        ->name('api-tokens.retailer-scraper');
    Route::post('/api-tokens/retailer-scraper', [ApiTokenController::class, 'storeRetailerScraper'])
        ->name('api-tokens.retailer-scraper.store');
    Route::delete('/api-tokens/{token}', [ApiTokenController::class, 'destroy'])
        ->whereNumber('token')
        ->name('api-tokens.destroy');

    Route::post('/onboarding/hide', [OnboardingController::class, 'hide'])->name('onboarding.hide');
    Route::post('/onboarding/show', [OnboardingController::class, 'show'])->name('onboarding.show');
    Route::post('/onboarding/skip', [OnboardingController::class, 'skip'])->name('onboarding.skip');
    Route::post('/onboarding/tours/{key}', [OnboardingController::class, 'updateTour'])
        ->name('onboarding.tours.update');

    Route::get('/accounts', [AccountController::class, 'index'])->name('accounts.index');
    Route::get('/accounts/create', [AccountController::class, 'create'])->name('accounts.create');
    Route::post('/accounts', [AccountController::class, 'store'])->name('accounts.store');
    Route::get('/accounts/{account}/edit', [AccountController::class, 'edit'])->name('accounts.edit');
    Route::put('/accounts/{account}', [AccountController::class, 'update'])->name('accounts.update');
    Route::get('/accounts/{account}/imports', [AccountImportController::class, 'index'])
        ->name('accounts.imports.index');
    Route::post('/accounts/{account}/imports', [AccountImportController::class, 'store'])
        ->name('accounts.imports.store');
    Route::get('/accounts/{account}/imports/{importBatch}', [ImportBatchController::class, 'showForAccount'])
        ->name('accounts.imports.show');
    Route::get('/accounts/{account}', [AccountController::class, 'show'])->name('accounts.show');

    Route::get('/venmo/imports', [VenmoImportController::class, 'index'])
        ->name('venmo.imports.index');
    Route::post('/venmo/imports', [VenmoImportController::class, 'store'])
        ->name('venmo.imports.store');
    Route::get('/venmo/imports/{importBatch}', [ImportBatchController::class, 'showForVenmo'])
        ->name('venmo.imports.show');

    Route::get('/categories', [CategoryController::class, 'index'])->name('categories.index');
    Route::get('/categories/create', [CategoryController::class, 'create'])->name('categories.create');
    Route::post('/categories', [CategoryController::class, 'store'])->name('categories.store');
    Route::get('/categories/{category}/edit', [CategoryController::class, 'edit'])->name('categories.edit');
    Route::patch('/categories/{category}', [CategoryController::class, 'update'])->name('categories.update');
    Route::delete('/categories/{category}', [CategoryController::class, 'destroy'])->name('categories.destroy');

    Route::get('/budgets', [BudgetController::class, 'index'])->name('budgets.index');
    Route::put('/budgets', [BudgetController::class, 'update'])->name('budgets.update');
    Route::post('/budgets/years', [BudgetYearController::class, 'store'])->name('budgets.years.store');
    Route::patch('/budgets/years/{budgetYear}', [BudgetYearController::class, 'update'])->name('budgets.years.update');
    Route::post('/budgets/years/{budgetYear}/current', [BudgetYearController::class, 'makeCurrent'])
        ->name('budgets.years.current');

    Route::get('/plans', [PlannedTemplateController::class, 'index'])->name('plans.index');
    Route::put('/plans/leftover-origin', [PlannedTemplateController::class, 'updateLeftoverOrigin'])
        ->name('plans.leftover-origin.update');
    Route::post('/plans', [PlannedTemplateController::class, 'store'])->name('plans.store');
    Route::patch('/plans/{plannedTemplate}', [PlannedTemplateController::class, 'update'])->name('plans.update');
    Route::put('/plans/{plannedTemplate}/assignments', [PlannedTemplateController::class, 'updateAssignments'])
        ->name('plans.assignments.update');
    Route::delete('/plans/{plannedTemplate}', [PlannedTemplateController::class, 'destroy'])->name('plans.destroy');
    Route::patch('/plans/occurrences/{plannedOccurrence}', [PlannedOccurrenceController::class, 'update'])
        ->name('plans.occurrences.update');
    Route::post('/plans/occurrences/{plannedOccurrence}/link', [PlannedOccurrenceController::class, 'link'])
        ->name('plans.occurrences.link');

    Route::get('/rules', [RuleController::class, 'index'])->name('rules.index');
    Route::delete('/rules/income/description-only', [IncomeClassificationRuleController::class, 'destroyDescriptionOnly'])
        ->name('rules.income.destroy-description-only');
    Route::patch('/rules/income/{rule}', [IncomeClassificationRuleController::class, 'update'])
        ->name('rules.income.update');
    Route::delete('/rules/income/{rule}', [IncomeClassificationRuleController::class, 'destroy'])
        ->name('rules.income.destroy');

    Route::redirect('/categorization-rules', '/rules?tab=expenses')
        ->name('categorization-rules.index');
    Route::patch('/categorization-rules/{rule}', [CategorizationRuleController::class, 'update'])
        ->name('categorization-rules.update');
    Route::delete('/categorization-rules/{rule}', [CategorizationRuleController::class, 'destroy'])
        ->name('categorization-rules.destroy');

    Route::get('/products', [ProductController::class, 'index'])->name('products.index');
    Route::post('/products/reconcile', [ProductController::class, 'reconcile'])->name('products.reconcile');
    Route::patch('/products/{product}/category', [ProductController::class, 'updateCategory'])
        ->name('products.category.update');

    Route::get('/orders', [OrderController::class, 'index'])->name('orders.index');
    Route::get('/orders/categorize', [OrderCategorizationController::class, 'index'])
        ->name('orders.categorize');
    Route::post('/orders/{order}/categorize-all', [OrderCategorizationController::class, 'categorizeAll'])
        ->name('orders.categorize-all');
    Route::post('/orders/{order}/categorize-all-this-time', [OrderCategorizationController::class, 'categorizeAllThisTime'])
        ->name('orders.categorize-all-this-time');
    Route::post('/orders/items/{item}/categorize-as-product', [OrderItemCategorizationController::class, 'store'])
        ->name('orders.items.categorize-as-product');
    Route::post('/orders/items/{item}/categorize-this-time', [OrderItemCategorizationController::class, 'storeInstance'])
        ->name('orders.items.categorize-this-time');
    Route::delete('/orders/items/{item}', [OrderItemCategorizationController::class, 'destroy'])
        ->name('orders.items.destroy');
    Route::get('/orders/{merchant}/imports', [RetailerImportController::class, 'index'])
        ->whereIn('merchant', ['walmart', 'amazon'])
        ->name('orders.imports.index');
    Route::post('/orders/{merchant}/imports', [RetailerImportController::class, 'store'])
        ->whereIn('merchant', ['walmart', 'amazon'])
        ->name('orders.imports.store');
    Route::get('/orders/{merchant}/imports/{importBatch}', [ImportBatchController::class, 'showForMerchant'])
        ->whereIn('merchant', ['walmart', 'amazon'])
        ->name('orders.imports.show');
    Route::get('/orders/{merchant}/{order}', [OrderController::class, 'detail'])
        ->whereIn('merchant', ['walmart', 'amazon'])
        ->whereNumber('order')
        ->name('orders.detail');
    Route::delete('/orders/{merchant}/{order}', [OrderController::class, 'destroy'])
        ->whereIn('merchant', ['walmart', 'amazon'])
        ->whereNumber('order')
        ->name('orders.destroy');
    Route::get('/orders/{merchant}', [OrderController::class, 'show'])
        ->whereIn('merchant', ['walmart', 'amazon'])
        ->name('orders.show');

    Route::post('/merchants/merge', [MerchantController::class, 'merge'])->name('merchants.merge');
    Route::get('/merchants/{merchant}', [MerchantController::class, 'show'])->name('merchants.show');
    Route::patch('/merchants/{merchant}', [MerchantController::class, 'update'])->name('merchants.update');
    Route::post('/merchants/{merchant}/rules/check', [MerchantMatchingRuleController::class, 'check'])
        ->name('merchants.rules.check');
    Route::post('/merchants/{merchant}/rules', [MerchantMatchingRuleController::class, 'store'])
        ->name('merchants.rules.store');
    Route::patch('/merchants/{merchant}/rules/{rule}', [MerchantMatchingRuleController::class, 'update'])
        ->name('merchants.rules.update');
    Route::delete('/merchants/{merchant}/rules/{rule}', [MerchantMatchingRuleController::class, 'destroy'])
        ->name('merchants.rules.destroy');

    Route::get('/reconciliation/unmatched-transactions', [ReconciliationController::class, 'unmatchedTransactions'])
        ->name('reconciliation.unmatched-transactions');
    Route::get('/reconciliation/needs-review', [ReconciliationController::class, 'needsReview'])
        ->name('reconciliation.needs-review');
    Route::post('/reconciliation/run', [ReconciliationController::class, 'run'])->name('reconciliation.run');
    Route::post('/reconciliation/orders/{order}/components', [OrderComponentController::class, 'store'])
        ->name('reconciliation.orders.components.store');
    Route::delete('/reconciliation/orders/{order}/components/{component}', [OrderComponentController::class, 'destroy'])
        ->name('reconciliation.orders.components.destroy');
    Route::patch('/reconciliation/orders/{order}/items/{item}', [OrderItemController::class, 'update'])
        ->name('reconciliation.orders.items.update');
    Route::post('/reconciliation/orders/{order}/resolve-payments', [OrderPaymentResolutionController::class, 'store'])
        ->name('reconciliation.orders.resolve-payments');
    Route::delete('/reconciliation/orders/{order}/payments/{paymentIndex}', [OrderPaymentResolutionController::class, 'destroy'])
        ->whereNumber('paymentIndex')
        ->name('reconciliation.orders.payments.destroy');
    Route::post('/reconciliation/transfers/{transferLink}/confirm', [TransferLinkController::class, 'confirm'])
        ->name('reconciliation.transfers.confirm');
    Route::post('/reconciliation/transfers/{transferLink}/reject', [TransferLinkController::class, 'reject'])
        ->name('reconciliation.transfers.reject');
    Route::post('/reconciliation/venmo/{venmoActivity}/confirm', [VenmoMatchController::class, 'confirm'])
        ->name('reconciliation.venmo.confirm');
    Route::post('/reconciliation/venmo/{venmoActivity}/reject', [VenmoMatchController::class, 'reject'])
        ->name('reconciliation.venmo.reject');
    Route::post('/reconciliation/venmo/{venmoActivity}/assign', [VenmoMatchController::class, 'assign'])
        ->name('reconciliation.venmo.assign');
    Route::post('/reconciliation/transactions/{transaction}/categorize', [TransactionCategorizationController::class, 'store'])
        ->name('reconciliation.transactions.categorize');
    Route::patch('/reconciliation/orders/{order}/components/{component}/category', [OrderComponentCategoryController::class, 'update'])
        ->name('reconciliation.orders.components.category.update');
    Route::post('/reconciliation/reimbursement-groups', [ReimbursementGroupController::class, 'store'])
        ->name('reconciliation.reimbursement-groups.store');
    Route::post('/reconciliation/reimbursement-groups/{reimbursementGroup}/transactions', [ReimbursementGroupController::class, 'addTransactions'])
        ->name('reconciliation.reimbursement-groups.transactions.add');
    Route::delete('/reconciliation/reimbursement-groups/{reimbursementGroup}/transactions/{transaction}', [ReimbursementGroupController::class, 'removeTransaction'])
        ->name('reconciliation.reimbursement-groups.transactions.remove');
    Route::patch('/reconciliation/reimbursement-groups/{reimbursementGroup}/transactions/{transaction}', [ReimbursementGroupController::class, 'updateLeg'])
        ->name('reconciliation.reimbursement-groups.transactions.update');
    Route::post('/reconciliation/reimbursement-groups/{reimbursementGroup}/close', [ReimbursementGroupController::class, 'close'])
        ->name('reconciliation.reimbursement-groups.close');
    Route::post('/reconciliation/reimbursement-groups/{reimbursementGroup}/reopen', [ReimbursementGroupController::class, 'reopen'])
        ->name('reconciliation.reimbursement-groups.reopen');
    Route::delete('/reconciliation/reimbursement-groups/{reimbursementGroup}', [ReimbursementGroupController::class, 'destroy'])
        ->name('reconciliation.reimbursement-groups.destroy');

});
