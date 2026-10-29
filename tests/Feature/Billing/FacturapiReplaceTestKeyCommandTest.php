<?php

use App\Models\Company;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;

/**
 * Fase 6.27b (3): billing:facturapi-link --replace-key reemplaza sólo la
 * llave Test de una empresa ya vinculada, comprobada contra su organización.
 */
const PHASE_627B_REPLACE_ORG = 'replaceorg00000000000aaa';
const PHASE_627B_REPLACE_OLD = 'sk_test_REPLACE_OLD_STORED_KEY_aaaa';
const PHASE_627B_REPLACE_NEW = 'sk_test_REPLACE_NEW_FETCHED_KEY_bbbb';

beforeEach(function () {
    config([
        'services.facturapi.base_url' => 'https://example-pac.test/v2',
        'services.facturapi.user_key' => 'sk_user_REPLACE_COMMAND_USER_KEY_cc',
        'services.facturapi.test_key' => null,
        'billing.pac_mode' => 'test',
    ]);
    Http::preventStrayRequests();
});

function phase627bReplaceFake(string $newKey = PHASE_627B_REPLACE_NEW, string $ownerOrg = PHASE_627B_REPLACE_ORG): void
{
    Http::fake([
        'example-pac.test/v2/organizations/'.PHASE_627B_REPLACE_ORG.'/apikeys/test' => Http::response(json_encode($newKey), 200, ['Content-Type' => 'application/json']),
        'example-pac.test/v2/organizations/me' => Http::response(['id' => $ownerOrg, 'legal' => ['tax_id' => 'XIA190128J61'], 'certificate' => ['has_certificate' => false]]),
    ]);
}

function phase627bReplaceCompany(): Company
{
    return Company::factory()->withFacturapiOrganization(PHASE_627B_REPLACE_OLD, PHASE_627B_REPLACE_ORG)->create();
}

function phase627bReplace(Company $company, array $options = []): array
{
    $exit = Artisan::call('billing:facturapi-link', array_merge(['--company' => $company->id, '--replace-key' => true], $options));

    return [$exit, Artisan::output()];
}

function phase627bStoredKey(Company $company): ?string
{
    $encrypted = DB::table('facturapi_organizations')->where('company_id', $company->id)->value('test_secret_key');

    return $encrypted === null ? null : decrypt($encrypted, false);
}

test('dry-run obtiene la llave con la User Key, la comprueba y no guarda nada', function () {
    phase627bReplaceFake();
    $company = phase627bReplaceCompany();

    [$exit, $output] = phase627bReplace($company);

    expect($exit)->toBe(0)
        ->and($output)->toContain('dry-run')
        ->and($output)->toContain('sk_test_…aaaa')
        ->and($output)->toContain('sk_test_…bbbb')
        ->and($output)->not->toContain(PHASE_627B_REPLACE_NEW)
        ->and($output)->not->toContain(PHASE_627B_REPLACE_OLD)
        ->and(phase627bStoredKey($company))->toBe(PHASE_627B_REPLACE_OLD)
        ->and(DB::table('facturapi_organization_audits')->count())->toBe(0);

    Http::assertSent(fn (Request $r): bool => str_ends_with($r->url(), '/organizations/me')
        && $r->hasHeader('Authorization', 'Bearer '.PHASE_627B_REPLACE_NEW));
    Http::assertNotSent(fn (Request $r): bool => $r->method() !== 'GET');
});

test('--apply reemplaza la llave, conserva la organización y audita sin secretos', function () {
    phase627bReplaceFake();
    $company = phase627bReplaceCompany();

    [$exit, $output] = phase627bReplace($company, ['--apply' => true]);
    $audit = DB::table('facturapi_organization_audits')->first();

    expect($exit)->toBe(0)
        ->and($output)->toContain('sk_test_…aaaa → sk_test_…bbbb')
        ->and(phase627bStoredKey($company))->toBe(PHASE_627B_REPLACE_NEW)
        ->and(DB::table('facturapi_organizations')->where('company_id', $company->id)->value('organization_id'))->toBe(PHASE_627B_REPLACE_ORG)
        ->and($audit->action)->toBe('test_key_replaced')
        ->and($audit->source)->toBe('console')
        ->and(json_decode($audit->metadata, true))->toBe(['previous_key_hint' => 'sk_test_…aaaa', 'key_hint' => 'sk_test_…bbbb', 'from' => 'user-key']);
});

test('--from=env usa la llave de .env sin necesitar la User Key', function () {
    config(['services.facturapi.user_key' => null, 'services.facturapi.test_key' => PHASE_627B_REPLACE_NEW]);
    Http::fake(['example-pac.test/v2/organizations/me' => Http::response(['id' => PHASE_627B_REPLACE_ORG])]);
    $company = phase627bReplaceCompany();

    [$exit] = phase627bReplace($company, ['--from' => 'env', '--apply' => true]);

    expect($exit)->toBe(0)->and(phase627bStoredKey($company))->toBe(PHASE_627B_REPLACE_NEW);
    Http::assertNotSent(fn (Request $r): bool => str_contains($r->url(), '/apikeys'));
});

test('una llave de otra organización no reemplaza nada', function () {
    phase627bReplaceFake(ownerOrg: 'someoneelse0000000000aaa');
    $company = phase627bReplaceCompany();

    [$exit, $output] = phase627bReplace($company, ['--apply' => true]);

    expect($exit)->toBe(1)
        ->and($output)->toContain('someoneelse0000000000aaa')
        ->and(phase627bStoredKey($company))->toBe(PHASE_627B_REPLACE_OLD);
});

test('la misma llave no es un cambio', function () {
    phase627bReplaceFake(PHASE_627B_REPLACE_OLD);
    $company = phase627bReplaceCompany();

    [$exit, $output] = phase627bReplace($company, ['--apply' => true]);

    expect($exit)->toBe(0)
        ->and($output)->toContain('No hay cambios')
        ->and(DB::table('facturapi_organization_audits')->count())->toBe(0);
});

test('sin vínculo, con otra organización, con --from inválido o sin User Key no contacta a Facturapi', function () {
    $linked = phase627bReplaceCompany();

    [$exit, $output] = phase627bReplace(Company::factory()->create(), ['--apply' => true]);
    expect($exit)->toBe(1)->and($output)->toContain('no está vinculada');

    [$exit, $output] = phase627bReplace($linked, ['--organization' => 'otherorg', '--apply' => true]);
    expect($exit)->toBe(1)->and($output)->toContain('nunca cambia de organización');

    [$exit] = phase627bReplace($linked, ['--from' => 'archivo']);
    expect($exit)->toBe(1);

    config(['services.facturapi.user_key' => 'sk_test_NOT_A_USER_KEY']);
    [$exit, $output] = phase627bReplace($linked, ['--apply' => true]);
    expect($exit)->toBe(1)->and($output)->toContain('pac_user_key_invalid');

    expect(phase627bStoredKey($linked))->toBe(PHASE_627B_REPLACE_OLD);
    Http::assertNothingSent();
});

test('el vínculo inicial también queda auditado', function () {
    config(['services.facturapi.test_key' => PHASE_627B_REPLACE_NEW]);
    Http::fake(['example-pac.test/v2/organizations/me' => Http::response(['id' => PHASE_627B_REPLACE_ORG])]);
    $company = Company::factory()->create();

    $exit = Artisan::call('billing:facturapi-link', ['--company' => $company->id, '--organization' => PHASE_627B_REPLACE_ORG, '--apply' => true]);
    $audit = DB::table('facturapi_organization_audits')->first();

    expect($exit)->toBe(0)
        ->and($audit->action)->toBe('test_key_stored')
        ->and(json_decode($audit->metadata, true))->toBe(['key_hint' => 'sk_test_…bbbb', 'from' => 'env']);
});
