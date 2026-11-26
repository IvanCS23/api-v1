<?php

use App\Enums\InvoiceStatus;
use App\Enums\Permission;
use App\Enums\QuoteStatus;
use App\Enums\SaleStatus;
use App\Enums\UserRole;
use App\Models\Client;
use App\Models\Company;
use App\Models\Invoice;
use App\Models\Product;
use App\Models\Quote;
use App\Models\Sale;
use App\Models\User;

use function Pest\Laravel\actingAs;

/*
 * Rol Client (solo *.view, ver RoleService): consulta pero no modifica.
 *
 * Los FormRequest validan antes del authorize() del controlador, así que
 * cada escritura se repite con un Admin: si a él le funciona, el payload es
 * válido y el 403 del Client viene del rol, no de la validación.
 */
function clientRoleSetup(): array
{
    $company = Company::factory()->create();

    return [
        $company,
        User::factory()->client()->create(['company_id' => $company->id]),
        User::factory()->create(['company_id' => $company->id, 'role' => UserRole::Admin]),
    ];
}

test('un Client puede consultar productos y clientes', function () {
    [$company, $client] = clientRoleSetup();
    Product::factory()->count(2)->create(['company_id' => $company->id]);
    $customer = Client::factory()->create(['company_id' => $company->id]);

    actingAs($client, 'api')->getJson('/api/products')->assertOk()->assertJsonCount(2);
    actingAs($client, 'api')->getJson('/api/clients')->assertOk();
    actingAs($client, 'api')->getJson("/api/clients/{$customer->id}")->assertOk();
});

test('un Client recibe 403 al crear, editar o borrar productos', function () {
    [$company, $client, $admin] = clientRoleSetup();
    $product = Product::factory()->create(['company_id' => $company->id, 'name' => 'Original']);
    $payload = [
        'name' => 'Asesoría', 'descripcion' => 'Honorarios', 'precio_unitario' => 1000,
        'clave_producto' => '43232408', 'clave_unidad' => 'E48', 'objeto_imp' => '02',
        'taxes' => [['tax' => '002', 'kind' => 'traslado', 'factor' => 'tasa', 'rate' => 0.16]],
    ];

    actingAs($client, 'api')->postJson('/api/products', $payload)->assertForbidden();
    actingAs($client, 'api')->putJson("/api/products/{$product->id}", ['name' => 'Cambiado'])->assertForbidden();
    actingAs($client, 'api')->deleteJson("/api/products/{$product->id}")->assertForbidden();

    expect(Product::withoutGlobalScopes()->where('company_id', $company->id)->count())->toBe(1)
        ->and($product->fresh()->name)->toBe('Original');

    actingAs($admin, 'api')->postJson('/api/products', $payload)->assertCreated();
    actingAs($admin, 'api')->putJson("/api/products/{$product->id}", ['name' => 'Cambiado'])->assertOk();
    actingAs($admin, 'api')->deleteJson("/api/products/{$product->id}")->assertOk();
});

test('un Client recibe 403 al crear, editar o borrar clientes', function () {
    [$company, $client, $admin] = clientRoleSetup();
    $customer = Client::factory()->create(['company_id' => $company->id, 'name' => 'Original']);
    $payload = [
        'name' => 'Cliente nuevo', 'email' => 'cliente.nuevo@example.com', 'rfc' => 'CCC010101AAA',
        'codigo_postal' => '12345', 'regimen_fiscal' => '601', 'uso_cfdi' => 'G03',
        'calle' => 'Calle 1', 'no_exterior' => '1', 'colonia' => 'Centro', 'localidad' => 'Loc',
        'municipio' => 'Mun', 'estado' => 'CDMX', 'pais' => 'México',
    ];

    actingAs($client, 'api')->postJson('/api/clients', $payload)->assertForbidden();
    actingAs($client, 'api')->putJson("/api/clients/{$customer->id}", ['name' => 'Cambiado'])->assertForbidden();
    actingAs($client, 'api')->deleteJson("/api/clients/{$customer->id}")->assertForbidden();

    expect(Client::withoutGlobalScopes()->where('company_id', $company->id)->count())->toBe(1)
        ->and($customer->fresh()->name)->toBe('Original');

    actingAs($admin, 'api')->postJson('/api/clients', $payload)->assertCreated();
    actingAs($admin, 'api')->putJson("/api/clients/{$customer->id}", ['name' => 'Cambiado'])->assertOk();
    actingAs($admin, 'api')->deleteJson("/api/clients/{$customer->id}")->assertNoContent();
});

test('un Client recibe 403 al crear, editar, borrar o mover ventas', function () {
    [$company, $client, $admin] = clientRoleSetup();
    $customer = Client::factory()->create(['company_id' => $company->id]);
    $sale = Sale::factory()->create(['company_id' => $company->id, 'client_id' => $customer->id, 'status' => SaleStatus::Draft, 'notes' => 'Original']);

    actingAs($client, 'api')->postJson('/api/sales', ['client_id' => $customer->id])->assertForbidden();
    actingAs($client, 'api')->putJson("/api/sales/{$sale->id}", ['notes' => 'Cambiado'])->assertForbidden();
    actingAs($client, 'api')->postJson("/api/sales/{$sale->id}/cancel")->assertForbidden();
    actingAs($client, 'api')->deleteJson("/api/sales/{$sale->id}")->assertForbidden();

    expect(Sale::withoutGlobalScopes()->where('company_id', $company->id)->count())->toBe(1)
        ->and($sale->fresh()->notes)->toBe('Original')
        ->and($sale->fresh()->status)->toBe(SaleStatus::Draft);

    actingAs($admin, 'api')->postJson('/api/sales', ['client_id' => $customer->id])->assertCreated();
    actingAs($admin, 'api')->putJson("/api/sales/{$sale->id}", ['notes' => 'Cambiado'])->assertOk();
    actingAs($admin, 'api')->deleteJson("/api/sales/{$sale->id}")->assertNoContent();
});

test('un Client recibe 403 al crear, editar, borrar o enviar cotizaciones', function () {
    [$company, $client, $admin] = clientRoleSetup();
    $customer = Client::factory()->create(['company_id' => $company->id]);
    $quote = Quote::factory()->create(['company_id' => $company->id, 'client_id' => $customer->id, 'status' => QuoteStatus::Draft, 'notes' => 'Original']);

    actingAs($client, 'api')->postJson('/api/quotes', ['client_id' => $customer->id])->assertForbidden();
    actingAs($client, 'api')->putJson("/api/quotes/{$quote->id}", ['notes' => 'Cambiado'])->assertForbidden();
    actingAs($client, 'api')->postJson("/api/quotes/{$quote->id}/send")->assertForbidden();
    actingAs($client, 'api')->deleteJson("/api/quotes/{$quote->id}")->assertForbidden();

    expect(Quote::withoutGlobalScopes()->where('company_id', $company->id)->count())->toBe(1)
        ->and($quote->fresh()->notes)->toBe('Original')
        ->and($quote->fresh()->status)->toBe(QuoteStatus::Draft);

    actingAs($admin, 'api')->postJson('/api/quotes', ['client_id' => $customer->id])->assertCreated();
    actingAs($admin, 'api')->putJson("/api/quotes/{$quote->id}", ['notes' => 'Cambiado'])->assertOk();
    actingAs($admin, 'api')->deleteJson("/api/quotes/{$quote->id}")->assertNoContent();
});

test('un Client recibe 403 al crear, editar o borrar facturas, pero puede consultarlas', function () {
    [$company, $client, $admin] = clientRoleSetup();
    $sale = Sale::factory()->create(['company_id' => $company->id, 'status' => SaleStatus::Draft]);
    $invoice = Invoice::factory()->create(['company_id' => $company->id, 'status' => InvoiceStatus::Draft, 'notes' => 'Original']);

    actingAs($client, 'api')->getJson("/api/invoices/{$invoice->id}")->assertOk();
    actingAs($client, 'api')->postJson('/api/invoices', ['sale_id' => $sale->id])->assertForbidden();
    actingAs($client, 'api')->putJson("/api/invoices/{$invoice->id}", ['notes' => 'Cambiado'])->assertForbidden();
    actingAs($client, 'api')->deleteJson("/api/invoices/{$invoice->id}")->assertForbidden();

    expect(Invoice::withoutGlobalScopes()->where('company_id', $company->id)->count())->toBe(1)
        ->and($invoice->fresh()->notes)->toBe('Original');

    // El Admin sí pasa la autorización: la venta en Draft no es facturable
    // (422 de negocio, no 403).
    actingAs($admin, 'api')->postJson('/api/invoices', ['sale_id' => $sale->id])->assertUnprocessable();
    actingAs($admin, 'api')->putJson("/api/invoices/{$invoice->id}", ['notes' => 'Cambiado'])->assertOk();
    actingAs($admin, 'api')->deleteJson("/api/invoices/{$invoice->id}")->assertNoContent();
});

test('un Admin puede crear un usuario con role=client', function () {
    [, , $admin] = clientRoleSetup();

    $response = actingAs($admin, 'api')->postJson('/api/users', [
        'name' => 'Cliente portal',
        'email' => 'cliente.portal@example.com',
        'password' => 'contraseña-segura-1',
        'password_confirmation' => 'contraseña-segura-1',
        'role' => UserRole::Client->value,
    ]);

    $response->assertCreated()->assertJsonPath('user.role', 'client');
    expect(User::query()->findOrFail($response->json('user.id'))->role)->toBe(UserRole::Client);
});

test('GET /api/roles devuelve los 5 roles con etiqueta y permisos', function () {
    [, $client] = clientRoleSetup();

    $roles = actingAs($client, 'api')->getJson('/api/roles')->assertOk()->json('data');

    expect(array_column($roles, 'value'))->toBe(['admin', 'accountant', 'sales', 'employee', 'client'])
        ->and(array_column($roles, 'label'))->toBe(['Administrador', 'Contador', 'Ventas', 'Empleado', 'Cliente'])
        ->and(collect($roles)->firstWhere('value', 'client')['permissions'])->each->toEndWith('.view');
});

test('GET /api/roles/me devuelve el rol y los permisos del usuario actual', function () {
    [, $client, $admin] = clientRoleSetup();
    $viewPermissions = array_values(array_map(
        fn (Permission $p): string => $p->value,
        array_filter(
            Permission::cases(),
            fn (Permission $p): bool => str_ends_with($p->value, '.view') && $p !== Permission::PaymentsView,
        ),
    ));

    $me = actingAs($client, 'api')->getJson('/api/roles/me')
        ->assertOk()
        ->assertExactJson(['role' => 'client', 'label' => 'Cliente', 'permissions' => $viewPermissions]);

    // Pagos solo para Admin y Accountant (igual que PaymentPolicy).
    expect(collect($me->json('permissions'))->filter(fn (string $p): bool => str_starts_with($p, 'payments.')))->toBeEmpty();

    actingAs($admin, 'api')->getJson('/api/roles/me')
        ->assertOk()
        ->assertJsonPath('role', 'admin')
        ->assertJsonCount(count(Permission::cases()), 'permissions');
});
