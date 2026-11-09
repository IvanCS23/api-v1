<?php

use Illuminate\Support\Facades\Storage;
use League\Flysystem\PathTraversalDetected;

/**
 * Fase 6.33 (4): Flysystem 3.36 con el adaptador local real (Storage::fake
 * usa un directorio temporal con el mismo adaptador). Las rutas que arma la
 * app (empresa/factura/archivo) se guardan y leen igual; una ruta que sale
 * del disco se rechaza.
 */
test('guarda, lee y borra archivos privados en rutas anidadas', function () {
    $disk = Storage::fake('local');

    $paths = [
        'billing/companies/1/invoices/10/cfdi.xml',
        'billing/companies/1/invoices/10/cfdi.pdf',
        'billing/companies/1/cancellations/10/acuse.xml',
        'companies/1/logo/logo con espacios.png',
    ];

    foreach ($paths as $path) {
        expect($disk->put($path, "contenido de {$path}"))->toBeTrue()
            ->and($disk->exists($path))->toBeTrue()
            ->and($disk->get($path))->toBe("contenido de {$path}");
    }

    expect($disk->files('billing/companies/1/invoices/10'))->toHaveCount(2);

    $disk->delete($paths[0]);
    expect($disk->exists($paths[0]))->toBeFalse();
});

test('una ruta que sale del disco se rechaza', function (string $path) {
    $disk = Storage::fake('local');

    expect(fn () => $disk->get($path))->toThrow(PathTraversalDetected::class);
})->with([
    '../fuera.txt',
    'billing/../../fuera.txt',
    'billing\\..\\..\\fuera.txt',
]);
