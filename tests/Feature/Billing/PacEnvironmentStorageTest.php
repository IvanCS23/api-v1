<?php

use App\Console\Commands\ReencryptSecrets;
use App\Models\Company;
use App\Models\FacturapiOrganization;
use App\Support\Billing\PacEnvironment;
use Illuminate\Database\QueryException;
use Illuminate\Encryption\Encrypter;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;

/**
 * Fase 6.31 (2): ambiente de la empresa y llave Live guardada. Todavía no
 * cambia ningún comportamiento: nada lee la llave Live.
 */
function phase631Organization(): FacturapiOrganization
{
    $company = Company::factory()->withFacturapiOrganization()->create();

    return FacturapiOrganization::withoutGlobalScopes()->where('company_id', $company->id)->firstOrFail();
}

it('guarda test como ambiente por omisión', function (): void {
    $organization = phase631Organization();

    expect(DB::table('facturapi_organizations')->where('id', $organization->id)->value('pac_environment'))->toBe('test')
        ->and((new FacturapiOrganization)->pac_environment)->toBe('test');
});

it('rechaza en la base un ambiente vacío o desconocido', function (string $value): void {
    $organization = phase631Organization();

    expect(fn () => DB::table('facturapi_organizations')->where('id', $organization->id)->update(['pac_environment' => $value]))
        ->toThrow(QueryException::class);
})->with(['', 'prod', 'LIVE']);

it('no deja el ambiente ni la llave Live como asignables en masa', function (): void {
    $organization = new FacturapiOrganization;

    expect($organization->isFillable('pac_environment'))->toBeFalse()
        ->and($organization->isFillable('live_secret_key'))->toBeFalse();
});

it('cifra la llave Live, la oculta y sólo muestra su pista', function (): void {
    $organization = phase631Organization();
    $key = 'sk_live_'.str_repeat('Q', 30).'z9Y8';

    $organization->setLiveSecretKey($key)->save();

    $raw = DB::table('facturapi_organizations')->where('id', $organization->id)->value('live_secret_key');

    expect($raw)->not->toContain($key)
        ->and(Crypt::decryptString($raw))->toBe($key)
        ->and($organization->fresh()->live_key_hint)->toBe('sk_live_…z9Y8')
        ->and($organization->fresh()->live_key_set_at)->not->toBeNull()
        ->and(json_encode($organization->fresh()))->not->toContain($key)
        ->and(array_key_exists('live_secret_key', $organization->fresh()->toArray()))->toBeFalse();
});

it('incluye la llave Live en security:reencrypt', function (): void {
    expect(ReencryptSecrets::COLUMNS['facturapi_organizations'])->toContain('live_secret_key');

    $previous = Encrypter::generateKey((string) config('app.cipher'));
    $current = Encrypter::generateKey((string) config('app.cipher'));
    $organization = phase631Organization();
    $old = new Encrypter($previous, (string) config('app.cipher'));

    DB::table('facturapi_organizations')->where('id', $organization->id)->update([
        'test_secret_key' => $old->encryptString('sk_test_ROTATION_KEY_1234567890'),
        'live_secret_key' => $old->encryptString('sk_live_ROTATION_KEY_1234567890'),
    ]);

    Crypt::swap((new Encrypter($current, (string) config('app.cipher')))->previousKeys([$previous]));

    expect(Artisan::call('security:reencrypt', ['--apply' => true]))->toBe(0);

    $stored = DB::table('facturapi_organizations')->where('id', $organization->id)->value('live_secret_key');

    expect((new Encrypter($current, (string) config('app.cipher')))->decryptString($stored))->toBe('sk_live_ROTATION_KEY_1234567890')
        ->and(Artisan::output())->not->toContain('sk_live_ROTATION');
});

it('rechaza un techo vacío o desconocido', function (mixed $ceiling): void {
    config(['billing.pac_mode' => $ceiling]);

    expect(PacEnvironment::ceiling())->toBeNull()
        ->and(PacEnvironment::violation('test'))->toBe('pac_mode_unsupported')
        ->and(PacEnvironment::violation('live'))->toBe('pac_mode_unsupported');
})->with([null, '', 'prod', 'TEST', 'sandbox']);

it('rechaza un ambiente vacío o desconocido', function (mixed $environment): void {
    config(['billing.pac_mode' => 'live']);

    expect(PacEnvironment::violation($environment))->toBe('pac_environment_invalid');
})->with([null, '', 'prod', 'LIVE']);

it('con el techo en test Live no opera (paro de emergencia) y Test sí', function (): void {
    config(['billing.pac_mode' => 'test']);
    app()['env'] = 'production';

    expect(PacEnvironment::violation('live'))->toBe('pac_live_not_enabled')
        ->and(PacEnvironment::violation('test'))->toBeNull();
});

it('Live sólo opera con un APP_ENV permitido, sin excepción para testing', function (string $appEnv, ?string $expected): void {
    config(['billing.pac_mode' => 'live']);
    app()['env'] = $appEnv;

    expect(PacEnvironment::violation('live'))->toBe($expected)
        ->and(PacEnvironment::violation('test'))->toBeNull();
})->with([
    ['production', null],
    ['testing', 'pac_live_app_env_not_allowed'],
    ['local', 'pac_live_app_env_not_allowed'],
    ['staging', 'pac_live_app_env_not_allowed'],
]);

it('la lista de APP_ENV permitidos es sólo production', function (): void {
    expect(config('billing.live_allowed_app_envs'))->toBe(['production']);

    config(['billing.pac_mode' => 'live', 'billing.live_allowed_app_envs' => []]);
    app()['env'] = 'production';

    expect(PacEnvironment::violation('live'))->toBe('pac_live_app_env_not_allowed');
});
