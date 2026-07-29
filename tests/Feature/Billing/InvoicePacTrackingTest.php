<?php

use App\Http\Resources\InvoiceResource;
use App\Models\Company;
use App\Models\Invoice;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

test('pac_response y pac_last_error nunca aparecen en el Resource ni en toArray/toJson', function () {
    $company = Company::factory()->create();
    $invoice = Invoice::factory()->create(['company_id' => $company->id]);
    $invoice->forceFill([
        'pac_response' => ['id' => 'inv_secret', 'raw' => 'dato interno del PAC'],
        'pac_last_error' => 'Detalle interno de diagnóstico, no debe salir a la API',
    ])->save();

    $invoice->refresh();

    $array = $invoice->toArray();
    $json = (new InvoiceResource($invoice))->response()->getContent();

    expect($array)->not->toHaveKey('pac_response')
        ->and($array)->not->toHaveKey('pac_last_error')
        ->and($json)->not->toContain('dato interno del PAC')
        ->and($json)->not->toContain('Detalle interno de diagnóstico');
});

test('pac_response se guarda cifrado en la base de datos (cast encrypted:array)', function () {
    $company = Company::factory()->create();
    $invoice = Invoice::factory()->create(['company_id' => $company->id]);
    $invoice->forceFill([
        'pac_response' => ['secret_field' => 'valor-que-no-debe-verse-en-texto-plano'],
    ])->save();

    $rawValue = DB::table('invoices')->where('id', $invoice->id)->value('pac_response');

    expect($rawValue)->not->toBeNull()
        ->and($rawValue)->not->toContain('valor-que-no-debe-verse-en-texto-plano')
        ->and($rawValue)->not->toContain('secret_field');

    // Pero a través del modelo (que sí puede desencriptar) se recupera intacto.
    expect($invoice->fresh()->pac_response)->toBe(['secret_field' => 'valor-que-no-debe-verse-en-texto-plano']);
});

test('stamped_at y last_pac_sync_at son CarbonImmutable (immutable_datetime)', function () {
    $company = Company::factory()->create();
    $invoice = Invoice::factory()->create(['company_id' => $company->id]);
    $invoice->forceFill([
        'stamped_at' => now(),
        'last_pac_sync_at' => now(),
    ])->save();

    $fresh = $invoice->fresh();

    expect($fresh->stamped_at)->toBeInstanceOf(\Carbon\CarbonImmutable::class)
        ->and($fresh->last_pac_sync_at)->toBeInstanceOf(\Carbon\CarbonImmutable::class);
});

test('las columnas de tracking PAC no son asignables masivamente (no fillable)', function () {
    $company = Company::factory()->create();
    $invoice = Invoice::factory()->create(['company_id' => $company->id]);

    $invoice->fill([
        'pac_provider' => 'facturapi',
        'pac_external_id' => 'inv_mass_assign',
        'cfdi_uuid' => 'AAAAAAAA-BBBB-CCCC-DDDD-EEEEEEEEEEEE',
        'pac_status' => 'valid',
    ]);

    expect($invoice->pac_provider)->toBeNull()
        ->and($invoice->pac_external_id)->toBeNull()
        ->and($invoice->cfdi_uuid)->toBeNull()
        ->and($invoice->pac_status)->toBeNull();
});

test('la migración de tracking PAC es válida: --pretend no falla y las columnas quedan bien definidas', function () {
    // La suite ya aplicó esta migración vía RefreshDatabase al arrancar
    // este test (es un archivo normal de database/migrations/), así que
    // para esta conexión ya no está "pendiente" — --pretend sobre el
    // set completo simplemente no debe fallar (prueba de humo del
    // comando en sí, sin asumir que hay algo pendiente que imprimir).
    $exitCode = Artisan::call('migrate', ['--pretend' => true]);
    expect($exitCode)->toBe(0);

    // La evidencia real de que la migración es válida: si su SQL fuera
    // incorrecto, RefreshDatabase habría fallado al aplicarla y NINGUNA
    // prueba de este archivo (ni de tests/Feature/Billing) habría
    // podido correr siquiera. Confirmamos explícitamente que las 9
    // columnas y los 2 índices únicos quedaron creados como se diseñó.
    expect(Schema::hasColumns('invoices', [
        'pac_provider',
        'pac_external_id',
        'cfdi_uuid',
        'pac_status',
        'cancellation_status',
        'stamped_at',
        'last_pac_sync_at',
        'pac_response',
        'pac_last_error',
    ]))->toBeTrue();

    $indexes = collect(Schema::getIndexes('invoices'))->pluck('name');
    expect($indexes)->toContain('erp_invoices_pac_provider_external_unique')
        ->and($indexes)->toContain('erp_invoices_pac_cfdi_uuid_unique');
});
