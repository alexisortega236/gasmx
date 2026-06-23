<?php

use App\Http\Controllers\Api\V1\FuelFillupController;
use App\Http\Controllers\Api\V1\StationController;
use Illuminate\Support\Facades\Route;

Route::prefix('v1')->group(function () {
    Route::get('/stations/nearby', [StationController::class, 'nearby']);
    Route::get('/stations/nearby/summary', [StationController::class, 'nearbySummary']);
    Route::get(
    '/stations/nearby/recommendation',
    [StationController::class, 'recommendation']
    );
    Route::get('/stations/{station}', [StationController::class, 'show']);

    Route::middleware('auth:sanctum')->group(function () {
        Route::get(
            '/fillups/pending-performance',
            [FuelFillupController::class, 'pendingPerformance']
        );

        Route::post('/fillups', [FuelFillupController::class, 'store']);
        Route::post(

        '/fillups/{fillup}/performance',

        [FuelFillupController::class, 'storePerformance']

    );
    });
});