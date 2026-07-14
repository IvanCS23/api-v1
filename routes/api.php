<?php

use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\ClientController;
use App\Http\Controllers\Api\EmployeController;
use App\Http\Controllers\Api\InvoiceController;
use App\Http\Controllers\Api\ProductController;
use App\Http\Controllers\Api\UserController;
use App\Http\Controllers\Api\UserSecurityController;
use Illuminate\Support\Facades\Route;

// Public routes
Route::get('/login', function () {
    $frontendUrl = rtrim(config('cors.allowed_origins.0', 'http://localhost:3000'), '/');

    return redirect()->away($frontendUrl.'/login');
});
Route::post('/login', [AuthController::class, 'login'])->middleware('throttle:10,1');

// Protected routes (require valid API token)
Route::middleware(['auth:api', 'throttle:60,1'])->group(function () {
    Route::get('/user', [AuthController::class, 'user']);
    Route::post('/logout', [AuthController::class, 'logout']);

    // Clients
    Route::get('/clients', [ClientController::class, 'index']);
    Route::post('/clients', [ClientController::class, 'store']);
    Route::put('/clients/{id}', [ClientController::class, 'update']);
    Route::get('/clients/{id}', [ClientController::class, 'show']);
    Route::delete('/clients/{id}', [ClientController::class, 'destroy']);

    // Products
    Route::get('/products', [ProductController::class, 'index']);
    Route::post('/products', [ProductController::class, 'store']);
    Route::put('/products/{id}', [ProductController::class, 'update']);
    Route::get('/products/{id}', [ProductController::class, 'show']);
    Route::delete('/products/{id}', [ProductController::class, 'destroy']);

    // Users (company team management)
    Route::get('/users', [UserController::class, 'index']);
    Route::post('/users', [UserController::class, 'store']);
    Route::get('/users/{id}', [UserController::class, 'show']);
    Route::put('/users/{id}', [UserController::class, 'update']);
    Route::delete('/users/{id}', [UserController::class, 'destroy']);

    // User security actions
    Route::patch('/users/{id}/password', [UserSecurityController::class, 'changePassword']);
    Route::post('/users/{id}/password/reset', [UserSecurityController::class, 'resetPassword']);
    Route::post('/users/{id}/sessions/revoke', [UserSecurityController::class, 'revokeSessions']);
    Route::patch('/users/{id}/must-change-password', [UserSecurityController::class, 'updateMustChangePassword']);

    // Employees
    Route::get('/employees', [EmployeController::class, 'index']);
    Route::post('/employees', [EmployeController::class, 'store']);
    Route::put('/employees/{id}', [EmployeController::class, 'update']);
    Route::get('/employees/{id}', [EmployeController::class, 'show']);
    Route::delete('/employees/{id}', [EmployeController::class, 'destroy']);

    // Invoices / tickets
    Route::get('/invoices', [InvoiceController::class, 'index']);
    Route::post('/invoices', [InvoiceController::class, 'store']);
    Route::put('/invoices/{id}', [InvoiceController::class, 'update']);
    Route::get('/invoices/{id}', [InvoiceController::class, 'show']);
    Route::delete('/invoices/{id}', [InvoiceController::class, 'destroy']);
});
