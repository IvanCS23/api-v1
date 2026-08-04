<?php

use App\Models\Company;
use App\Services\Billing\CompanyBillingReadinessService;

test('una Company con default_payment_form válido y sin default_payment_method está ready (omisión deliberada, no error)', function () {
    $company = Company::factory()->create(['default_payment_form' => '03', 'default_payment_method' => null]);

    $result = app(CompanyBillingReadinessService::class)->evaluate($company);

    expect($result['ready'])->toBeTrue()
        ->and($result['errors'])->toBe([]);
});

test('default_payment_form ausente produce COMPANY_PAYMENT_FORM_MISSING', function () {
    $company = Company::factory()->create(['default_payment_form' => null]);

    $result = app(CompanyBillingReadinessService::class)->evaluate($company);

    expect($result['ready'])->toBeFalse()
        ->and(collect($result['errors'])->pluck('code'))->toContain('COMPANY_PAYMENT_FORM_MISSING');
});

test('default_payment_form con formato inválido produce COMPANY_PAYMENT_FORM_INVALID_FORMAT', function (string $invalid) {
    $company = Company::factory()->create(['default_payment_form' => $invalid]);

    $result = app(CompanyBillingReadinessService::class)->evaluate($company);

    expect($result['ready'])->toBeFalse()
        ->and(collect($result['errors'])->pluck('code'))->toContain('COMPANY_PAYMENT_FORM_INVALID_FORMAT');
})->with(['3', '003', 'AB']);

test('default_payment_method = PUE es válido', function () {
    $company = Company::factory()->create(['default_payment_form' => '03', 'default_payment_method' => 'PUE']);

    $result = app(CompanyBillingReadinessService::class)->evaluate($company);

    expect($result['ready'])->toBeTrue();
});

test('default_payment_method = PPD es válido', function () {
    $company = Company::factory()->create(['default_payment_form' => '03', 'default_payment_method' => 'PPD']);

    $result = app(CompanyBillingReadinessService::class)->evaluate($company);

    expect($result['ready'])->toBeTrue();
});

test('default_payment_method con un valor que no es PUE ni PPD produce COMPANY_PAYMENT_METHOD_INVALID', function () {
    $company = Company::factory()->create(['default_payment_form' => '03', 'default_payment_method' => 'CASH']);

    $result = app(CompanyBillingReadinessService::class)->evaluate($company);

    expect($result['ready'])->toBeFalse()
        ->and(collect($result['errors'])->pluck('code'))->toContain('COMPANY_PAYMENT_METHOD_INVALID');
});

test('ninguna suposición silenciosa: el servicio nunca escribe en Company, solo evalúa', function () {
    $company = Company::factory()->create(['default_payment_form' => null, 'default_payment_method' => null]);
    $originalUpdatedAt = $company->updated_at;

    app(CompanyBillingReadinessService::class)->evaluate($company);

    expect($company->fresh()->default_payment_form)->toBeNull()
        ->and($company->fresh()->default_payment_method)->toBeNull()
        ->and($company->fresh()->updated_at->equalTo($originalUpdatedAt))->toBeTrue();
});
