<?php

use App\Models\Company;
use App\Models\User;
use App\Support\Sat\SatCatalog;
use Illuminate\Support\Facades\DB;
use Tests\Support\SatCatalogFixture;

/*
| Fase 6.26: catálogos SAT locales. Los pequeños se leen de los CSV
| oficiales versionados; los grandes se importan a tablas. Ninguno consulta
| al PAC.
*/

function satUser(): User
{
    return User::factory()->create(['company_id' => Company::factory()->create()->id]);
}

test('los catálogos pequeños vienen del CSV oficial: régimen, uso, forma de pago y objeto de impuesto', function () {
    expect(SatCatalog::taxRegimes())->toHaveCount(19)
        ->and(SatCatalog::cfdiUses())->toHaveCount(24)
        ->and(SatCatalog::paymentForms())->toHaveCount(22)
        ->and(SatCatalog::taxObjects())->toHaveCount(8);

    expect(SatCatalog::taxRegimes()['601'])->toMatchArray(['physical' => false, 'moral' => true])
        ->and(SatCatalog::taxRegimes()['616'])->toMatchArray(['physical' => true, 'moral' => false])
        ->and(SatCatalog::taxRegimes()['626'])->toMatchArray(['physical' => true, 'moral' => true]);

    expect(SatCatalog::isPaymentForm('31'))->toBeTrue()
        ->and(SatCatalog::isPaymentForm('07'))->toBeFalse()
        ->and(SatCatalog::isTaxObject('08'))->toBeTrue()
        ->and(SatCatalog::isTaxObject('09'))->toBeFalse();
});

test('la matriz régimen ↔ uso ↔ tipo de persona es la del archivo del SAT', function () {
    $uses = SatCatalog::cfdiUses();

    expect($uses['G03'])->toMatchArray(['physical' => true, 'moral' => true])
        ->and($uses['G03']['regimes'])->toContain('601', '612', '626')->not->toContain('616', '605')
        ->and($uses['D01'])->toMatchArray(['physical' => true, 'moral' => false])
        ->and($uses['D01']['regimes'])->toContain('605', '612')->not->toContain('601')
        ->and($uses['S01']['regimes'])->toContain('616', '601')
        ->and($uses['CN01']['regimes'])->toBe(['605']);
});

test('sat:import-catalogs carga las claves desde el CSV y es idempotente', function () {
    $path = base_path('tests/Fixtures/sat');

    $this->artisan('sat:import-catalogs', ['--path' => $path])->assertSuccessful();
    $this->artisan('sat:import-catalogs', ['--path' => $path])->assertSuccessful();

    expect(DB::table('sat_product_keys')->count())->toBe(5)
        ->and(DB::table('sat_unit_keys')->count())->toBe(3)
        ->and(DB::table('sat_product_keys')->where('code', '01010101')->value('similar_words'))->toBe('Público en general')
        ->and(DB::table('sat_unit_keys')->where('code', 'KGM')->value('symbol'))->toBe('kg')
        // La muestra de SatCatalogFixture se reemplazó: lo que no está en el CSV se retira.
        ->and(DB::table('sat_unit_keys')->where('code', 'ACT')->exists())->toBeFalse();
});

test('sat:import-catalogs falla sin tocar las tablas si falta un CSV o una columna', function () {
    $empty = sys_get_temp_dir().'/sat-import-'.uniqid();
    mkdir($empty);
    file_put_contents("{$empty}/clave_prod_serv.csv", "clave,descripcion\n01010101,X\n");
    file_put_contents("{$empty}/clave_unidad.csv", "clave,nombre,simbolo,vigencia_inicio,vigencia_fin\nH87,Pieza,,,\n");

    $this->artisan('sat:import-catalogs', ['--path' => $empty])->assertFailed();
    $this->artisan('sat:import-catalogs', ['--path' => $empty.'/no-existe'])->assertFailed();

    expect(DB::table('sat_product_keys')->count())->toBe(count(SatCatalogFixture::PRODUCT_KEYS));

    array_map('unlink', glob("{$empty}/*.csv"));
    rmdir($empty);
});

test('el CSV versionado del repositorio importa el catálogo completo', function () {
    $this->artisan('sat:import-catalogs')->assertSuccessful();

    expect(DB::table('sat_product_keys')->count())->toBe(52513)
        ->and(DB::table('sat_unit_keys')->count())->toBe(2418)
        ->and(SatCatalog::productKeyExists('50211503'))->toBeTrue()
        ->and(SatCatalog::unitKeyExists('H87'))->toBeTrue()
        ->and(SatCatalog::unitKeyExists('888'))->toBeFalse();
});

test('una clave con fin de vigencia vencido no cuenta como existente', function () {
    DB::table('sat_product_keys')->insert(['code' => '99999999', 'description' => 'Retirada', 'valid_to' => '2023-12-31']);

    expect(SatCatalog::productKeyExists('99999999'))->toBeFalse()
        ->and(SatCatalog::productKeyExists('43232408'))->toBeTrue()
        ->and(SatCatalog::existingProductKeys(['43232408', '99999999', null, '']))->toBe(['43232408' => true]);
});

test('los endpoints de catálogo exigen sesión', function () {
    $this->getJson('/api/sat/catalogs')->assertUnauthorized();
    $this->getJson('/api/sat/product-keys?q=432')->assertUnauthorized();
    $this->getJson('/api/sat/unit-keys?q=H87')->assertUnauthorized();
});

test('GET /api/sat/catalogs entrega los catálogos pequeños con la matriz de compatibilidad', function () {
    $response = $this->actingAs(satUser(), 'api')->getJson('/api/sat/catalogs')->assertOk();

    expect($response->json('tax_regimes'))->toHaveCount(19)
        ->and($response->json('payment_forms'))->toHaveCount(22)
        ->and($response->json('tax_objects'))->toHaveCount(8)
        ->and($response->json('tax_regimes.0'))->toBe(['code' => '601', 'description' => 'General de Ley Personas Morales', 'physical' => false, 'moral' => true])
        ->and(collect($response->json('cfdi_uses'))->firstWhere('code', 'CN01'))->toMatchArray(['physical' => true, 'moral' => false, 'regimes' => ['605']]);
});

test('GET /api/sat/product-keys busca por prefijo de clave o por texto, con límite', function () {
    $user = satUser();

    $this->actingAs($user, 'api')->getJson('/api/sat/product-keys?q=4323')
        ->assertOk()
        ->assertExactJson([['code' => '43232408', 'description' => 'Software de desarrollo de plataformas web']]);

    $this->actingAs($user, 'api')->getJson('/api/sat/product-keys?q=factura')
        ->assertOk()
        ->assertJsonPath('0.code', '84111506');

    $this->actingAs($user, 'api')->getJson('/api/sat/product-keys?q=')->assertOk()->assertExactJson([]);

    DB::table('sat_product_keys')->insert(collect(range(1, 30))->map(fn (int $i): array => [
        'code' => sprintf('7000%04d', $i), 'description' => "Servicio {$i}",
    ])->all());

    expect($this->actingAs($user, 'api')->getJson('/api/sat/product-keys?q=7000')->json())->toHaveCount(20);
});

test('GET /api/sat/unit-keys pone primero la clave exacta y también busca por nombre', function () {
    $user = satUser();

    $this->actingAs($user, 'api')->getJson('/api/sat/unit-keys?q=h87')
        ->assertOk()
        ->assertJsonPath('0', ['code' => 'H87', 'name' => 'Pieza', 'symbol' => null]);

    $this->actingAs($user, 'api')->getJson('/api/sat/unit-keys?q=kilo')
        ->assertOk()
        ->assertJsonPath('0.code', 'KGM');
});
