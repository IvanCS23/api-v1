<?php

use Illuminate\Support\Facades\Route;

/**
 * Fase 6.31: el disco `local` (storage/app/private: XML/PDF de los CFDI,
 * acuses y logotipos) no registra las rutas /storage/{path} de Laravel
 * (`serve`). Sólo se sirve por los endpoints autenticados.
 */
test('el disco local no se sirve por HTTP', function () {
    expect(config('filesystems.disks.local.serve'))->toBeFalse();
});

test('ninguna ruta registrada sirve ni recibe archivos de storage', function () {
    $offenders = collect(Route::getRoutes()->getRoutes())
        ->filter(fn ($route): bool => str_starts_with($route->uri(), 'storage/')
            || str_starts_with((string) $route->getName(), 'storage.'))
        ->map(fn ($route): string => implode('|', $route->methods()).' '.$route->uri())
        ->values()
        ->all();

    expect($offenders)->toBe([]);
});

test('GET y PUT a /storage/{path} responden 404', function (string $method) {
    $this->call($method, '/storage/cfdi/1/1/archivo.xml?upload=1')->assertNotFound();
})->with(['GET', 'PUT']);
