<?php

use App\Models\Company;
use App\Services\Sales\SaleNumberGenerator;

test('el primer folio de una empresa es VTA-00000001', function () {
    $company = Company::factory()->create();

    $folio = (new SaleNumberGenerator())->next($company->id);

    expect($folio)->toBe('VTA-00000001');
});

test('los folios se incrementan secuencialmente dentro de la misma empresa', function () {
    $company = Company::factory()->create();
    $generator = new SaleNumberGenerator();

    // El generador solo calcula el folio; para simular ventas ya
    // existentes hay que persistirlas (el generador lee el último folio
    // guardado, no lleva un contador en memoria).
    \App\Models\Sale::factory()->create(['company_id' => $company->id, 'folio' => $generator->next($company->id)]);
    \App\Models\Sale::factory()->create(['company_id' => $company->id, 'folio' => $generator->next($company->id)]);

    expect($generator->next($company->id))->toBe('VTA-00000003');
});

test('los folios son independientes entre empresas distintas', function () {
    $companyA = Company::factory()->create();
    $companyB = Company::factory()->create();
    $generator = new SaleNumberGenerator();

    \App\Models\Sale::factory()->create(['company_id' => $companyA->id, 'folio' => $generator->next($companyA->id)]);

    expect($generator->next($companyB->id))->toBe('VTA-00000001');
});
