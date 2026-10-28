<?php

use App\Models\Company;
use App\Models\FacturapiOrganization;
use App\Models\Invoice;
use App\Models\User;
use App\Support\Tenant\CurrentTenant;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Fase 6.27 (1/7): modelo de la organización Facturapi por empresa y
 * protección de su llave.
 */
const PHASE_627_KEY = 'sk_test_PHASE_627_MODEL_SECRET_a1b2';

function phase627Organization(?Company $company = null): FacturapiOrganization
{
    $company ??= Company::factory()->create();

    return FacturapiOrganization::factory()->testKey(PHASE_627_KEY)->create([
        'company_id' => $company->id,
        'organization_id' => '6ad00a7079761a6ddec6026a',
    ]);
}

test('las migraciones son válidas: --pretend no falla', function () {
    expect(Artisan::call('migrate', ['--pretend' => true]))->toBe(0);
});

test('la llave se guarda cifrada en reposo y se lee descifrada con el modelo', function () {
    $organization = phase627Organization();

    $raw = DB::table('facturapi_organizations')->where('id', $organization->id)->value('test_secret_key');

    expect($raw)->not->toBe(PHASE_627_KEY)
        ->and($raw)->not->toContain('PHASE_627')
        ->and(decrypt($raw, false))->toBe(PHASE_627_KEY);

    app(CurrentTenant::class)->set($organization->company_id);
    expect(FacturapiOrganization::query()->first()->test_secret_key)->toBe(PHASE_627_KEY);
});

test('la llave nunca aparece al serializar; sólo la pista con los últimos 4 caracteres', function () {
    $organization = phase627Organization();

    $json = $organization->fresh()->toJson();

    expect($json)->not->toContain(PHASE_627_KEY)
        ->and($json)->not->toContain('test_secret_key')
        ->and($organization->test_key_hint)->toBe('sk_test_…a1b2')
        ->and($organization->test_key_set_at)->not->toBeNull();
});

test('la llave no es asignable en masa', function () {
    $company = Company::factory()->create();

    $organization = new FacturapiOrganization([
        'organization_id' => 'org_mass_assignment',
        'test_secret_key' => PHASE_627_KEY,
        'test_key_hint' => 'sk_test_…fake',
    ]);

    expect($organization->test_secret_key)->toBeNull()
        ->and($organization->test_key_hint)->toBeNull()
        ->and($company->id)->toBeInt();
});

test('la pista nunca revela una llave corta', function (string $key, string $hint) {
    expect(FacturapiOrganization::hint($key))->toBe($hint);
})->with([
    'test' => ['sk_test_ABCDEFGHIJKL1234', 'sk_test_…1234'],
    'corta' => ['sk_test_abc', 'sk_test_…'],
    'sin prefijo' => ['abcdefghijklmnop', '…mnop'],
]);

test('/api/user no expone la llave ni la organización de la empresa', function () {
    $organization = phase627Organization();
    $user = User::factory()->create(['company_id' => $organization->company_id]);

    $response = $this->actingAs($user, 'api')->getJson('/api/user');

    $response->assertOk();
    expect($response->getContent())->not->toContain(PHASE_627_KEY)
        ->and($response->getContent())->not->toContain('test_secret_key')
        ->and($response->getContent())->not->toContain('6ad00a7079761a6ddec6026a');
});

test('una empresa tiene una sola organización y una organización una sola empresa', function () {
    $organization = phase627Organization();

    expect(fn () => FacturapiOrganization::factory()->create(['company_id' => $organization->company_id]))
        ->toThrow(QueryException::class)
        ->and(fn () => FacturapiOrganization::factory()->create(['organization_id' => $organization->organization_id]))
        ->toThrow(QueryException::class);
});

test('fuera del tenant de su empresa la organización no se encuentra', function () {
    $organization = phase627Organization();
    $other = Company::factory()->create();

    app(CurrentTenant::class)->set($other->id);
    expect(FacturapiOrganization::query()->find($organization->id))->toBeNull();

    app(CurrentTenant::class)->clear();
    expect(FacturapiOrganization::query()->find($organization->id))->toBeNull();
});

test('el estado de factory deja a la empresa con organización y llave', function () {
    $company = Company::factory()->withFacturapiOrganization('sk_test_FACTORY_STATE_9876')->create();

    app(CurrentTenant::class)->set($company->id);

    expect($company->facturapiOrganization->test_secret_key)->toBe('sk_test_FACTORY_STATE_9876')
        ->and($company->facturapiOrganization->test_key_hint)->toBe('sk_test_…9876');
});

test('invoices gana la organización PAC y su archivado; companies pierde api_key', function () {
    expect(Schema::hasColumns('invoices', ['pac_organization_id', 'pac_organization_archived_at', 'pac_organization_archive_reason']))->toBeTrue()
        ->and(Schema::hasColumn('companies', 'api_key'))->toBeFalse()
        ->and(in_array('api_key', (new Company)->getFillable(), true))->toBeFalse();

    $invoice = Invoice::factory()->create();
    expect(in_array('pac_organization_id', $invoice->getFillable(), true))->toBeFalse();
});

test('eliminar companies.api_key aborta si alguna empresa tiene valor', function () {
    Schema::table('companies', fn ($table) => $table->string('api_key')->nullable());
    $company = Company::factory()->create();
    DB::table('companies')->where('id', $company->id)->update(['api_key' => 'sk_test_LEGACY_PLAIN']);

    $migration = require database_path('migrations/2026_10_27_010200_drop_api_key_from_companies_table.php');

    expect(fn () => $migration->up())->toThrow(RuntimeException::class, 'companies.api_key tiene valor en 1 empresa(s)')
        ->and(Schema::hasColumn('companies', 'api_key'))->toBeTrue();

    DB::table('companies')->update(['api_key' => null]);
    $migration->up();
    expect(Schema::hasColumn('companies', 'api_key'))->toBeFalse();
});
