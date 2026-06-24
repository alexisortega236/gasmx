<?php

use App\Http\Controllers\Api\V1\AuthController;
use App\Http\Controllers\Api\V1\FuelFillupController;
use App\Http\Controllers\Api\V1\StationController;
use Illuminate\Support\Facades\Route;

Route::prefix('v1')->group(function () {
    /*
     * Estaciones públicas.
     */
    Route::get('/stations/nearby', [StationController::class, 'nearby']);
    Route::get('/stations/nearby/summary', [StationController::class, 'nearbySummary']);
    Route::get(
        '/stations/nearby/recommendation',
        [StationController::class, 'recommendation']
    );
    Route::get('/stations/{station}', [StationController::class, 'show']);

    /*
     * Autenticación pública.
     */
    Route::prefix('auth')->group(function () {
        Route::post('/register', [AuthController::class, 'register']);
        Route::post('/login', [AuthController::class, 'login']);
    });

    /*
     * Rutas protegidas con token Sanctum.
     */
    Route::middleware('auth:sanctum')->group(function () {
        Route::get('/me', [AuthController::class, 'me']);
        Route::post('/auth/logout', [AuthController::class, 'logout']);
        Route::post(
        '/fillups/{fillup}/dismiss-performance',
        [FuelFillupController::class, 'dismissPerformance']
        );
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