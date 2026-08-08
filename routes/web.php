<?php

use App\Http\Controllers\Accounts\AccountController;
use App\Http\Controllers\Auth\AuthenticatedSessionController;
use App\Http\Controllers\Auth\RegisteredUserController;
use App\Http\Controllers\Imports\AmazonOrderImportController;
use App\Http\Controllers\Imports\BankTransactionImportController;
use App\Http\Controllers\Imports\ImportBatchController;
use App\Http\Controllers\Imports\WalmartOrderImportController;
use App\Http\Controllers\Orders\OrderController;
use App\Http\Controllers\Reconciliation\OrderComponentController;
use App\Http\Controllers\Reconciliation\OrderPaymentResolutionController;
use App\Http\Controllers\Reconciliation\ReconciliationController;
use Illuminate\Support\Facades\Route;

Route::redirect('/', '/imports');

Route::middleware('guest')->group(function () {
    Route::get('/register', [RegisteredUserController::class, 'create'])->name('register');
    Route::post('/register', [RegisteredUserController::class, 'store']);

    Route::get('/login', [AuthenticatedSessionController::class, 'create'])->name('login');
    Route::post('/login', [AuthenticatedSessionController::class, 'store']);
});

Route::middleware('auth')->group(function () {
    Route::post('/logout', [AuthenticatedSessionController::class, 'destroy'])->name('logout');

    Route::get('/imports', [ImportBatchController::class, 'index'])->name('imports.index');

    Route::get('/accounts', [AccountController::class, 'index'])->name('accounts.index');
    Route::get('/accounts/{account}', [AccountController::class, 'show'])->name('accounts.show');

    Route::get('/orders', [OrderController::class, 'index'])->name('orders.index');

    Route::get('/reconciliation', [ReconciliationController::class, 'index'])->name('reconciliation.index');
    Route::post('/reconciliation/run', [ReconciliationController::class, 'run'])->name('reconciliation.run');
    Route::post('/reconciliation/orders/{order}/components', [OrderComponentController::class, 'store'])
        ->name('reconciliation.orders.components.store');
    Route::delete('/reconciliation/orders/{order}/components/{component}', [OrderComponentController::class, 'destroy'])
        ->name('reconciliation.orders.components.destroy');
    Route::post('/reconciliation/orders/{order}/resolve-payments', [OrderPaymentResolutionController::class, 'store'])
        ->name('reconciliation.orders.resolve-payments');

    Route::get('/imports/bank-transactions/create', [BankTransactionImportController::class, 'create'])
        ->name('imports.bank-transactions.create');
    Route::post('/imports/bank-transactions', [BankTransactionImportController::class, 'store'])
        ->name('imports.bank-transactions.store');

    Route::get('/imports/walmart-orders/create', [WalmartOrderImportController::class, 'create'])
        ->name('imports.walmart-orders.create');
    Route::post('/imports/walmart-orders', [WalmartOrderImportController::class, 'store'])
        ->name('imports.walmart-orders.store');

    Route::get('/imports/amazon-orders/create', [AmazonOrderImportController::class, 'create'])
        ->name('imports.amazon-orders.create');
    Route::post('/imports/amazon-orders', [AmazonOrderImportController::class, 'store'])
        ->name('imports.amazon-orders.store');

    Route::get('/imports/{importBatch}', [ImportBatchController::class, 'show'])->name('imports.show');
});
