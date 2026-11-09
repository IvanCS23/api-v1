<?php

use Illuminate\Http\Client\StrayRequestException;
use Illuminate\Support\Facades\Http;

/**
 * Fase 6.32 (cierre): las pruebas no dependen del .env local ni salen a la
 * red. La llave Test es la ficticia de phpunit.xml y Pest activa
 * Http::preventStrayRequests() en cada prueba Feature.
 */
test('la llave Test de las pruebas es la ficticia de phpunit.xml', function () {
    expect(config('services.facturapi.test_key'))->toBe('sk_test_phpunit_ficticia_no_es_una_llave_real')
        ->and(config('services.facturapi.user_key'))->toBeEmpty();
});

test('una petición HTTP sin simular falla en lugar de salir a la red', function () {
    expect(fn () => Http::get('https://api.facturapi.io/v2/organizations'))->toThrow(StrayRequestException::class);
});
