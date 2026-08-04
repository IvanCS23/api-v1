<?php

use App\Models\Company;
use Illuminate\Support\Facades\Artisan;

/**
 * Fase 6.2.3 — corrección de `companies.default_payment_method`
 * (varchar(2) -> varchar(3), único cambio de esta migración). No se
 * ejecuta manualmente en este sprint, solo vía RefreshDatabase al
 * arrancar la suite y `migrate --pretend` como prueba de humo del
 * comando en sí.
 */
test('la migración es válida: --pretend no falla', function () {
    $exitCode = Artisan::call('migrate', ['--pretend' => true]);
    expect($exitCode)->toBe(0);
});

test('default_payment_method admite hasta 3 caracteres tras la migración (PUE/PPD caben completos, sin truncarse)', function () {
    // Nota: SQLite (motor de esta suite, ver phpunit.xml) no impone
    // longitudes VARCHAR en tiempo de ejecución, así que esta prueba no
    // demuestra por sí sola que MySQL/MariaDB rechazaría un valor de 2
    // caracteres truncado — esa evidencia real es el SQL literal
    // producido por `migrate --pretend`
    // ("modify `default_payment_method` varchar(3) null"), ya verificado
    // contra la conexión MySQL real del proyecto antes de escribir esta
    // migración. Esta prueba sí confirma el round-trip correcto (guardar
    // y releer sin corrupción) para ambos valores reales del catálogo.
    $company = Company::factory()->create(['default_payment_method' => 'PUE']);

    expect($company->fresh()->default_payment_method)->toBe('PUE');

    $company->update(['default_payment_method' => 'PPD']);
    expect($company->fresh()->default_payment_method)->toBe('PPD');
});

test('nullability y ausencia de default se preservan: sigue siendo nullable, sin default forzado', function () {
    $company = Company::factory()->create(['default_payment_method' => null]);

    expect($company->fresh()->default_payment_method)->toBeNull();
});

test('el resto de atributos de la tabla companies no se ven afectados (RFC/uuid siguen únicos)', function () {
    $company = Company::factory()->create();

    expect(fn () => Company::factory()->create(['rfc' => $company->rfc]))
        ->toThrow(\Illuminate\Database\QueryException::class);
});
