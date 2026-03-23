<?php

use App\Http\Controllers\OrderController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| API Routes
|--------------------------------------------------------------------------
|
| All routes are prefixed with /api automatically by Laravel.
|
*/

Route::prefix('orders')->group(function () {

    Route::post('/', [OrderController::class, 'store']);

    Route::get('/{order}', [OrderController::class, 'show']);

    Route::post('/{order}/cancel', [OrderController::class, 'cancel']);
});