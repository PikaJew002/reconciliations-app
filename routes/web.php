<?php

use App\Http\Controllers\Accounts\AccountController;
use App\Http\Controllers\Accounts\AccountImportController;
use App\Http\Controllers\Auth\AuthenticatedSessionController;
use App\Http\Controllers\Auth\RegisteredUserController;
use App\Http\Controllers\Budgets\BudgetController;
use App\Http\Controllers\Budgets\BudgetYearController;
use App\Http\Controllers\Categories\CategorizationRuleController;
use App\Http\Controllers\Categories\CategoryController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\Imports\ImportBatchController;
use App\Http\Controllers\Merchants\MerchantController;
use App\Http\Controllers\Orders\OrderCategorizationController;
use App\Http\Controllers\Orders\OrderController;
use App\Http\Controllers\Orders\OrderItemCategorizationController;
use App\Http\Controllers\Orders\RetailerImportController;
use App\Http\Controllers\Products\ProductController;
use App\Http\Controllers\Reconciliation\OrderComponentCategoryController;
use App\Http\Controllers\Reconciliation\OrderComponentController;
use App\Http\Controllers\Reconciliation\OrderItemController;
use App\Http\Controllers\Reconciliation\OrderPaymentResolutionController;
use App\Http\Controllers\Reconciliation\ReconciliationController;
use App\Http\Controllers\Reconciliation\ReimbursementGroupController;
use App\Http\Controllers\Reconciliation\TransactionCategorizationController;
use App\Http\Controllers\Reconciliation\TransferLinkController;
use App\Http\Controllers\Rules\IncomeClassificationRuleController;
use App\Http\Controllers\Rules\RuleController;
use Illuminate\Support\Facades\Route;

Route::middleware('guest')->group(function () {
    Route::get('/register', [RegisteredUserController::class, 'create'])->name('register');
    Route::post('/register', [RegisteredUserController::class, 'store']);

    Route::get('/login', [AuthenticatedSessionController::class, 'create'])->name('login');
    Route::post('/login', [AuthenticatedSessionController::class, 'store']);
});

Route::middleware('auth')->group(function () {
    Route::post('/logout', [AuthenticatedSessionController::class, 'destroy'])->name('logout');

    Route::get('/', [DashboardController::class, 'index'])->name('dashboard');

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
    Route::post('/orders/items/{item}/categorize-as-product', [OrderItemCategorizationController::class, 'store'])
        ->name('orders.items.categorize-as-product');
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
    Route::get('/orders/{merchant}', [OrderController::class, 'show'])
        ->whereIn('merchant', ['walmart', 'amazon'])
        ->name('orders.show');

    Route::get('/merchants/{merchant}', [MerchantController::class, 'show'])->name('merchants.show');

    Route::get('/reconciliation', [ReconciliationController::class, 'index'])->name('reconciliation.index');
    Route::get('/reconciliation/needs-review', [ReconciliationController::class, 'needsReview'])
        ->name('reconciliation.needs-review');
    Route::get('/reconciliation/matched', [ReconciliationController::class, 'matched'])
        ->name('reconciliation.matched');
    Route::get('/reconciliation/unmatched-orders', [ReconciliationController::class, 'unmatchedOrders'])
        ->name('reconciliation.unmatched-orders');
    Route::get('/reconciliation/unmatched-transactions', [ReconciliationController::class, 'unmatchedTransactions'])
        ->name('reconciliation.unmatched-transactions');
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