<?php

use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\OrganizationController;
use Illuminate\Support\Facades\Route;

// Public routes
Route::post('/login', [AuthController::class, 'login']);

// Protected routes (require valid API token)
Route::middleware('auth:api')->group(function () {
    Route::get('/user', [AuthController::class, 'user']);
    Route::post('/logout', [AuthController::class, 'logout']);

    // Organizations
    Route::apiResource('organizations', OrganizationController::class);

    // Fiscal data (upsert por organización)
    Route::post('organizations/{organization}/fiscal-data', [OrganizationController::class, 'storeFiscalData']);

    // Seals
    Route::post('organizations/{organization}/seals', [OrganizationController::class, 'storeSeals']);
    Route::delete('organizations/{organization}/seals/{seal}', [OrganizationController::class, 'destroySeal']);
});
