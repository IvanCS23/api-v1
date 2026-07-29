<?php

use App\Contracts\Billing\PacProvider;
use App\Infrastructure\Billing\Facturapi\FacturapiProvider;

test('el contrato PacProvider se resuelve a FacturapiProvider', function () {
    $provider = app(PacProvider::class);

    expect($provider)->toBeInstanceOf(FacturapiProvider::class)
        ->and($provider)->toBeInstanceOf(PacProvider::class);
});

test('el binding construye el adaptador con los valores de configuración', function () {
    config([
        'services.facturapi.base_url' => 'https://example-pac.test/v9',
        'services.facturapi.test_key' => 'sk_test_ABC123',
        'services.facturapi.timeout' => 42,
        'services.facturapi.connect_timeout' => 7,
    ]);

    // Se reconstruye el binding para que tome la config recién fijada
    // (el binding no es singleton, así que cada resolución vuelve a leer config()).
    $provider = app(PacProvider::class);

    $reflection = new ReflectionClass($provider);

    $baseUrl = $reflection->getProperty('baseUrl');
    $apiKey = $reflection->getProperty('apiKey');
    $timeout = $reflection->getProperty('timeout');
    $connectTimeout = $reflection->getProperty('connectTimeout');

    expect($baseUrl->getValue($provider))->toBe('https://example-pac.test/v9')
        ->and($apiKey->getValue($provider))->toBe('sk_test_ABC123')
        ->and($timeout->getValue($provider))->toBe(42)
        ->and($connectTimeout->getValue($provider))->toBe(7);
});
