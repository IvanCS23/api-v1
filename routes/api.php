<?php

use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\ClientController;
use App\Http\Controllers\Api\EmployeController;
use App\Http\Controllers\Api\InvoiceController;
use App\Http\Controllers\Api\ProductController;
use App\Http\Controllers\Api\QuoteController;
use App\Http\Controllers\Api\QuoteItemController;
use App\Http\Controllers\Api\SaleController;
use App\Http\Controllers\Api\SaleItemController;
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

    // Sales (Fase 2 — motor comercial, sin Facturapi)
    Route::get('/sales', [SaleController::class, 'index']);
    Route::post('/sales', [SaleController::class, 'store']);
    Route::put('/sales/{id}', [SaleController::class, 'update']);
    Route::get('/sales/{id}', [SaleController::class, 'show']);
    Route::delete('/sales/{id}', [SaleController::class, 'destroy']);

    // Sale workflow (Fase 4 — transiciones de estado vía acciones dedicadas)
    Route::post('/sales/{id}/submit', [SaleController::class, 'submit']);
    Route::post('/sales/{id}/confirm', [SaleController::class, 'confirm']);
    Route::post('/sales/{id}/cancel', [SaleController::class, 'cancel']);

    // Billing readiness (Fase 4 — preparación fiscal, sin Facturapi)
    Route::get('/sales/{id}/billing-readiness', [SaleController::class, 'billingReadiness']);

    // Sale items (anidados bajo una venta)
    Route::get('/sales/{sale}/items', [SaleItemController::class, 'index']);
    Route::post('/sales/{sale}/items', [SaleItemController::class, 'store']);
    Route::put('/sales/{sale}/items/{item}', [SaleItemController::class, 'update']);
    Route::delete('/sales/{sale}/items/{item}', [SaleItemController::class, 'destroy']);

    // Quotes (Fase 3 — cotizaciones, sin Facturapi)
    Route::get('/quotes', [QuoteController::class, 'index']);
    Route::post('/quotes', [QuoteController::class, 'store']);
    Route::put('/quotes/{id}', [QuoteController::class, 'update']);
    Route::get('/quotes/{id}', [QuoteController::class, 'show']);
    Route::delete('/quotes/{id}', [QuoteController::class, 'destroy']);
    Route::post('/quotes/{id}/convert', [QuoteController::class, 'convert']);

    // Quote workflow (Fase 4 — transiciones de estado vía acciones dedicadas)
    Route::post('/quotes/{id}/send', [QuoteController::class, 'send']);
    Route::post('/quotes/{id}/approve', [QuoteController::class, 'approve']);
    Route::post('/quotes/{id}/reject', [QuoteController::class, 'reject']);
    Route::post('/quotes/{id}/expire', [QuoteController::class, 'expire']);

    // Quote items (anidados bajo una cotización)
    Route::get('/quotes/{quote}/items', [QuoteItemController::class, 'index']);
    Route::post('/quotes/{quote}/items', [QuoteItemController::class, 'store']);
    Route::put('/quotes/{quote}/items/{item}', [QuoteItemController::class, 'update']);
    Route::delete('/quotes/{quote}/items/{item}', [QuoteItemController::class, 'destroy']);
});
