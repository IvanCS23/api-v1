<?php

use App\Enums\InvoiceStatus;
use App\Enums\UserRole;
use App\Enums\UserStatus;
use App\Models\Company;
use App\Models\Invoice;
use App\Models\Sale;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

/**
 * Fase 6.27b (0b): un usuario que deja de estar activo pierde su token y,
 * aunque conserve uno válido, no puede usar ninguna ruta autenticada.
 */
beforeEach(function () {
    Http::preventStrayRequests();
});

/** Deja al usuario con un token válido sin pasar por el modelo (que lo revocaría). */
function inactiveUserToken(User $user, UserStatus $status): string
{
    $plain = Str::random(80);
    DB::table('users')->where('id', $user->id)->update(['api_token' => hash('sha256', $plain), 'status' => $status->value]);

    return $plain;
}

/** Cada petición resuelve el usuario desde el token, como en producción. */
function inactiveUserRequest(string $token)
{
    app('auth')->forgetGuards();

    return test()->withToken($token);
}

test('un usuario desactivado con token válido no lista, no vende, no factura, no emite ni cancela', function (UserStatus $status) {
    $company = Company::factory()->create();
    $user = User::factory()->create(['company_id' => $company->id, 'role' => UserRole::Admin]);
    $sale = Sale::factory()->create(['company_id' => $company->id]);
    $invoice = Invoice::factory()->create(['company_id' => $company->id, 'status' => InvoiceStatus::Ready]);
    $token = inactiveUserToken($user, $status);
    $salesBefore = Sale::query()->count();
    $invoicesBefore = Invoice::query()->count();

    $routes = [
        ['GET', '/api/user'],
        ['GET', '/api/clients'],
        ['GET', '/api/invoices'],
        ['POST', '/api/sales'],
        ['POST', "/api/sales/{$sale->id}/invoice"],
        ['POST', '/api/invoices'],
        ['POST', "/api/invoices/{$invoice->id}/operations/issue"],
        ['POST', "/api/invoices/{$invoice->id}/pac/issue"],
        ['POST', "/api/invoices/{$invoice->id}/operations/cancel"],
        ['POST', "/api/invoices/{$invoice->id}/pac/cancel"],
    ];

    foreach ($routes as [$method, $uri]) {
        inactiveUserRequest($token)->json($method, $uri, ['client_id' => $sale->client_id, 'sale_id' => $sale->id])
            ->assertForbidden()
            ->assertExactJson(['code' => 'USER_INACTIVE', 'message' => 'Tu cuenta está desactivada. Contacta a un administrador.']);
    }

    expect(Sale::query()->count())->toBe($salesBefore)
        ->and(Invoice::query()->count())->toBe($invoicesBefore)
        ->and($invoice->fresh()->status)->toBe(InvoiceStatus::Ready)
        ->and($invoice->fresh()->pac_issue_status)->toBeNull();
    Http::assertNothingSent();
})->with([
    'inactivo' => [UserStatus::Inactive],
    'suspendido' => [UserStatus::Suspended],
]);

test('el mismo token funciona mientras el usuario está activo', function () {
    $user = User::factory()->create(['role' => UserRole::Admin]);
    $token = inactiveUserToken($user, UserStatus::Active);

    inactiveUserRequest($token)->getJson('/api/clients')->assertOk();
});

test('al desactivarlo se revoca su token y reactivarlo exige iniciar sesión otra vez', function () {
    $company = Company::factory()->create();
    $admin = User::factory()->create(['company_id' => $company->id, 'role' => UserRole::Admin]);
    $member = User::factory()->create(['company_id' => $company->id, 'role' => UserRole::Employee, 'password' => 'contraseña-del-empleado']);
    $adminToken = inactiveUserToken($admin, UserStatus::Active);
    $memberToken = inactiveUserToken($member, UserStatus::Active);

    inactiveUserRequest($memberToken)->getJson('/api/clients')->assertOk();

    inactiveUserRequest($adminToken)->deleteJson("/api/users/{$member->id}")->assertOk();

    expect(DB::table('users')->where('id', $member->id)->value('api_token'))->toBeNull();
    inactiveUserRequest($memberToken)->getJson('/api/clients')->assertUnauthorized();

    inactiveUserRequest($adminToken)->putJson("/api/users/{$member->id}", ['status' => UserStatus::Active->value])->assertOk();

    // Reactivado, el token anterior sigue sin valer.
    inactiveUserRequest($memberToken)->getJson('/api/clients')->assertUnauthorized();

    app('auth')->forgetGuards();
    $newToken = $this->postJson('/api/login', ['email' => $member->email, 'password' => 'contraseña-del-empleado'])
        ->assertOk()
        ->json('token');

    inactiveUserRequest($newToken)->getJson('/api/clients')->assertOk();
});

test('suspender editando el estado también revoca el token', function () {
    $company = Company::factory()->create();
    $admin = User::factory()->create(['company_id' => $company->id, 'role' => UserRole::Admin]);
    $member = User::factory()->create(['company_id' => $company->id, 'role' => UserRole::Sales]);
    $adminToken = inactiveUserToken($admin, UserStatus::Active);
    $memberToken = inactiveUserToken($member, UserStatus::Active);

    inactiveUserRequest($adminToken)->putJson("/api/users/{$member->id}", ['status' => UserStatus::Suspended->value])->assertOk();

    expect(DB::table('users')->where('id', $member->id)->value('api_token'))->toBeNull();
    inactiveUserRequest($memberToken)->getJson('/api/clients')->assertUnauthorized();
});

test('el modelo revoca el token sólo cuando el estado deja de ser activo', function () {
    $user = User::factory()->create();
    $user->forceFill(['api_token' => hash('sha256', 'token')])->save();

    $user->update(['name' => 'Otro nombre', 'status' => UserStatus::Active]);
    expect($user->fresh()->api_token)->not->toBeNull();

    $user->update(['status' => UserStatus::Inactive]);
    expect($user->fresh()->api_token)->toBeNull();
});

test('un usuario desactivado con sesión web abierta recibe 403 y la sesión se cierra', function (string $uri) {
    $user = User::factory()->create(['status' => UserStatus::Inactive]);

    $this->actingAs($user)->get($uri)->assertForbidden();

    $this->assertGuest('web');
})->with([
    'configuración' => ['/settings/profile'],
    'ruta de Fortify' => ['/user/confirm-password'],
]);
