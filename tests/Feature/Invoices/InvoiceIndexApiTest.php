<?php

use App\Models\Company;
use App\Models\Invoice;
use App\Models\InvoiceItem;
use App\Models\User;
use Illuminate\Support\Facades\DB;

function invoiceIndexUser(): array
{
    $company = Company::factory()->create();

    return [$company, User::factory()->create(['company_id' => $company->id])];
}

test('el listado tiene contrato explícito paginado y no incluye items ni campos internos', function () {
    [$company, $user] = invoiceIndexUser();
    $invoice = Invoice::factory()->create([
        'company_id' => $company->id,
    ]);
    $invoice->forceFill([
        'pac_external_id' => 'pac-secret-id',
        'cfdi_uuid' => 'AAAAAAAA-BBBB-4CCC-8DDD-EEEEEEEEEEEE',
    ])->save();
    InvoiceItem::factory()->create(['company_id' => $company->id, 'invoice_id' => $invoice->id]);

    $response = $this->actingAs($user, 'api')->getJson('/api/invoices');

    $response->assertOk()
        ->assertJsonStructure(['data', 'links', 'meta'])
        ->assertJsonCount(1, 'data');

    expect(array_keys($response->json('data.0')))->toBe([
        'id', 'client_id', 'folio', 'status', 'client_name', 'client_rfc',
        'client_regimen_fiscal', 'client_uso_cfdi', 'client_codigo_postal', 'subtotal',
        'discount_total', 'tax_total', 'total', 'currency', 'notes',
        'payment_form', 'payment_method', 'issued_at', 'cancelled_at',
        'created_at', 'updated_at',
    ])->and($response->json('data.0'))->not->toHaveKey('items')
        ->and($response->getContent())->not->toContain('pac-secret-id')
        ->and($response->getContent())->not->toContain('AAAAAAAA-BBBB');
});

test('pagina con límites permitidos y conserva items exclusivamente en detalle', function () {
    [$company, $user] = invoiceIndexUser();
    Invoice::factory()->count(25)->create(['company_id' => $company->id]);
    $invoice = Invoice::factory()->create(['company_id' => $company->id]);
    InvoiceItem::factory()->create(['company_id' => $company->id, 'invoice_id' => $invoice->id]);

    $page = $this->actingAs($user, 'api')->getJson('/api/invoices?per_page=10&page=2');
    $page->assertOk()->assertJsonCount(10, 'data')
        ->assertJsonPath('meta.current_page', 2)
        ->assertJsonPath('meta.per_page', 10)
        ->assertJsonPath('meta.total', 26);

    $this->actingAs($user, 'api')->getJson("/api/invoices/{$invoice->id}")
        ->assertOk()->assertJsonCount(1, 'items');
});

test('búsqueda y filtros se agrupan sin romper el aislamiento tenant', function () {
    [$company, $user] = invoiceIndexUser();
    $other = Company::factory()->create();
    Invoice::factory()->create(['company_id' => $company->id, 'folio' => 'FAC-ALFA', 'client_name' => 'Cliente Uno', 'client_rfc' => 'AAA010101AAA', 'status' => 'issued', 'payment_method' => 'PUE']);
    Invoice::factory()->create(['company_id' => $company->id, 'folio' => 'FAC-BETA', 'client_name' => 'Comercial Alfa', 'client_rfc' => 'BBB010101BBB', 'status' => 'draft', 'payment_method' => 'PPD']);
    Invoice::factory()->create(['company_id' => $other->id, 'folio' => 'FAC-ALFA-OTRA', 'client_name' => 'Alfa ajena']);

    $search = $this->actingAs($user, 'api')->getJson('/api/invoices?search=Alfa');
    $search->assertOk()->assertJsonCount(2, 'data');

    $filtered = $this->actingAs($user, 'api')->getJson('/api/invoices?search=Alfa&status=issued&payment_method=PUE');
    $filtered->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.folio', 'FAC-ALFA');
});

test('un OR de búsqueda jamás expone una coincidencia de otra empresa', function () {
    [$company, $user] = invoiceIndexUser();
    $other = Company::factory()->create();
    Invoice::factory()->create(['company_id' => $company->id, 'folio' => 'FAC-PROPIA', 'client_name' => 'Sin coincidencia']);
    Invoice::factory()->create(['company_id' => $other->id, 'folio' => 'FAC-BUSQUEDA', 'client_name' => 'FAC-BUSQUEDA', 'client_rfc' => 'BUS010101AAA']);

    $this->actingAs($user, 'api')->getJson('/api/invoices?search=FAC-BUSQUEDA')
        ->assertOk()->assertJsonCount(0, 'data');
});

test('busca parcialmente por cada campo permitido y devuelve vacío sin coincidencia', function (string $search) {
    [$company, $user] = invoiceIndexUser();
    Invoice::factory()->create(['company_id' => $company->id, 'folio' => 'FAC-UNICA-314', 'client_name' => 'Servicios Aurora Norte', 'client_rfc' => 'AUN010101XYZ']);

    $this->actingAs($user, 'api')->getJson('/api/invoices?search='.urlencode($search))
        ->assertOk()->assertJsonCount(1, 'data');

    $this->actingAs($user, 'api')->getJson('/api/invoices?search=SIN-COINCIDENCIA')
        ->assertOk()->assertJsonCount(0, 'data');
})->with(['UNICA-3', 'Aurora', '10101XY']);

test('valida status, fechas y paginación con 422 estable', function () {
    [$company, $user] = invoiceIndexUser();
    foreach (['draft', 'ready', 'issued', 'cancelled'] as $status) {
        Invoice::factory()->create(['company_id' => $company->id, 'status' => $status]);
        $this->actingAs($user, 'api')->getJson("/api/invoices?status={$status}")
            ->assertOk()->assertJsonCount(1, 'data');
    }

    $this->actingAs($user, 'api')->getJson('/api/invoices?status=invented&date_from=no-date&per_page=101')
        ->assertUnprocessable()->assertJsonValidationErrors(['status', 'date_from', 'per_page']);
    $this->actingAs($user, 'api')->getJson('/api/invoices?date_from=2026-08-10&date_to=2026-08-01')
        ->assertUnprocessable()->assertJsonValidationErrors('date_to');
});

test('aplica paginación default, 50 y máximo 100 sin permitir ilimitado', function () {
    [$company, $user] = invoiceIndexUser();
    Invoice::factory()->count(21)->create(['company_id' => $company->id]);

    $this->actingAs($user, 'api')->getJson('/api/invoices')
        ->assertOk()->assertJsonCount(20, 'data')->assertJsonPath('meta.per_page', 20);
    $this->actingAs($user, 'api')->getJson('/api/invoices?per_page=50')
        ->assertOk()->assertJsonCount(21, 'data')->assertJsonPath('meta.per_page', 50);
    $this->actingAs($user, 'api')->getJson('/api/invoices?per_page=100')
        ->assertOk()->assertJsonPath('meta.per_page', 100);
    $this->actingAs($user, 'api')->getJson('/api/invoices?per_page=0')
        ->assertUnprocessable()->assertJsonValidationErrors('per_page');
});

test('requiere autenticación y el detalle tampoco filtra campos internos', function () {
    $company = Company::factory()->create();
    $invoice = Invoice::factory()->create(['company_id' => $company->id]);
    $invoice->forceFill(['pac_external_id' => 'internal-pac-id', 'cfdi_xml_path' => 'private/path.xml'])->save();

    $this->getJson('/api/invoices')->assertUnauthorized();

    $user = User::factory()->create(['company_id' => $company->id]);
    $response = $this->actingAs($user, 'api')->getJson("/api/invoices/{$invoice->id}");
    $response->assertOk();
    expect($response->getContent())->not->toContain('internal-pac-id')
        ->and($response->getContent())->not->toContain('private/path.xml');
});

test('filtra por fecha efectiva usando issued_at y created_at solo cuando no hay emisión', function () {
    [$company, $user] = invoiceIndexUser();
    Invoice::factory()->create(['company_id' => $company->id, 'folio' => 'ISSUED-IN', 'issued_at' => '2026-06-15 12:00:00', 'created_at' => '2025-01-01 12:00:00']);
    Invoice::factory()->create(['company_id' => $company->id, 'folio' => 'DRAFT-IN', 'issued_at' => null, 'created_at' => '2026-06-20 12:00:00']);
    Invoice::factory()->create(['company_id' => $company->id, 'folio' => 'ISSUED-OUT', 'issued_at' => '2025-06-15 12:00:00', 'created_at' => '2026-06-10 12:00:00']);

    $response = $this->actingAs($user, 'api')->getJson('/api/invoices?date_from=2026-06-01&date_to=2026-06-30&sort=folio&direction=asc');

    $response->assertOk();
    expect(collect($response->json('data'))->pluck('folio')->all())->toBe(['DRAFT-IN', 'ISSUED-IN']);

    $this->actingAs($user, 'api')->getJson('/api/invoices?date_from=2026-06-18')
        ->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.folio', 'DRAFT-IN');
    $this->actingAs($user, 'api')->getJson('/api/invoices?date_to=2025-12-31')
        ->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.folio', 'ISSUED-OUT');
});

test('ordena únicamente por campos y direcciones permitidos', function () {
    [$company, $user] = invoiceIndexUser();
    Invoice::factory()->create(['company_id' => $company->id, 'folio' => 'FAC-LOW', 'total' => 10]);
    Invoice::factory()->create(['company_id' => $company->id, 'folio' => 'FAC-HIGH', 'total' => 500]);

    $this->actingAs($user, 'api')->getJson('/api/invoices?sort=total&direction=desc')
        ->assertOk()->assertJsonPath('data.0.folio', 'FAC-HIGH');

    $this->actingAs($user, 'api')->getJson('/api/invoices?sort=pac_status&direction=sideways&per_page=500')
        ->assertUnprocessable()->assertJsonValidationErrors(['sort', 'direction', 'per_page']);
});

test('el listado no consulta invoice_items', function () {
    [$company, $user] = invoiceIndexUser();
    $invoices = Invoice::factory()->count(5)->create(['company_id' => $company->id]);
    $invoices->each(fn ($invoice) => InvoiceItem::factory()->count(3)->create([
        'company_id' => $company->id,
        'invoice_id' => $invoice->id,
    ]));
    $itemQueries = 0;
    $invoiceQueries = 0;
    DB::listen(function ($query) use (&$invoiceQueries, &$itemQueries): void {
        $sql = strtolower($query->sql);
        if (str_contains($sql, 'invoice_items')) {
            $itemQueries++;
        }
        if (str_contains($sql, 'from "invoices"')) {
            $invoiceQueries++;
        }
    });

    $this->actingAs($user, 'api')->getJson('/api/invoices')->assertOk();

    expect($itemQueries)->toBe(0)
        ->and($invoiceQueries)->toBe(2);
});
