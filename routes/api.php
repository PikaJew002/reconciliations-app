<?php

use App\Http\Controllers\Orders\AmazonImportController;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

Route::get('/user', function (Request $request) {
    return $request->user();
})->middleware('auth:sanctum');

Route::post('/amazon/import', [AmazonImportController::class, 'store'])
    ->middleware(['auth:sanctum', 'abilities:amazon:import'])
    ->name('api.amazon.import');
