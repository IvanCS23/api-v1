<?php

use App\Enums\UserRole;
use App\Models\Company;
use App\Models\LegacyInvoice;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;

/**
 * Fase 6.31: las rutas /legacy-invoices se retiraron. La tabla
 * legacy_invoices no tiene company_id ni Policy: cualquier usuario de
 * cualquier empresa leía y modificaba todos sus registros. La tabla, el
 * modelo y los datos se conservan.
 */
test('ninguna ruta registrada apunta a legacy-invoices ni a LegacyInvoiceController', function () {
    $offenders = collect(Route::getRoutes()->getRoutes())
        ->filter(fn ($route): bool => str_contains($route->uri(), 'legacy-invoices')
            || str_contains((string) $route->getActionName(), 'LegacyInvoiceController'))
        ->map(fn ($route): string => implode('|', $route->methods()).' '.$route->uri())
        ->values()
        ->all();

    expect($offenders)->toBe([]);
});

test('un usuario autenticado recibe 404 en cada ruta retirada y el registro existente no cambia', function (string $method, string $uri) {
    $company = Company::factory()->create();
    $user = User::factory()->create(['company_id' => $company->id, 'role' => UserRole::Admin]);
    $legacy = LegacyInvoice::query()->create([
        'invoice_number' => 'TCK-20260521-ABCDEF',
        'status' => 'created',
        'document_type' => 'ticket',
        'emitter_name' => 'Emisor',
        'emitter_zip' => '06010',
        'items' => [['descripcion' => 'x', 'cantidad' => 1, 'precio' => 1, 'subtotal' => 1, 'total' => 1, 'claves' => '01010101']],
        'subtotal' => 1,
        'total' => 1,
        'currency' => 'MXN',
    ]);
    $before = (array) DB::table('legacy_invoices')->where('id', $legacy->id)->first();

    $this->actingAs($user, 'api')
        ->json($method, str_replace('{id}', (string) $legacy->id, $uri), ['status' => 'cancelled', 'total' => 999])
        ->assertNotFound();

    expect((array) DB::table('legacy_invoices')->where('id', $legacy->id)->first())->toBe($before);
})->with([
    'index' => ['GET', '/api/legacy-invoices'],
    'store' => ['POST', '/api/legacy-invoices'],
    'show' => ['GET', '/api/legacy-invoices/{id}'],
    'update' => ['PUT', '/api/legacy-invoices/{id}'],
    'destroy' => ['DELETE', '/api/legacy-invoices/{id}'],
]);
