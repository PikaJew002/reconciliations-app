<?php

use App\Http\Controllers\Orders\AmazonImportController;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

Route::get('/user', function (Request $request) {
    return $request->user();
})->middleware('auth:sanctum');

Route::middleware(['auth:sanctum', 'abilities:amazon:import'])->group(function () {
    Route::post('/amazon/import', [AmazonImportController::class, 'store'])
        ->name('api.amazon.import');
    Route::post('/amazon/orders/status', [AmazonImportController::class, 'status'])
        ->name('api.amazon.orders.status');
});
