<?php

use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\OrganizationController;
use App\Http\Controllers\Api\ClientController;
use Illuminate\Support\Facades\Route;


// Public routes
Route::get('/login', function () {
    $frontendUrl = rtrim(config('cors.allowed_origins.0', 'http://localhost:3000'), '/');

    return redirect()->away($frontendUrl.'/login');
});
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

//clients
    Route::get('/clients', [ClientController::class, 'index']);
    Route::post('/clients', [ClientController::class, 'store']);
    Route::put('/clients/{id}', [ClientController::class, 'update']);
    Route::get('/clients/{id}', [ClientController::class, 'show']);
    Route::delete('/clients/{id}', [ClientController::class, 'destroy']);
