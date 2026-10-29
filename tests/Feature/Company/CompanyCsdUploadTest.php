<?php

use App\Enums\UserRole;
use App\Models\Company;
use App\Models\FacturapiOrganization;
use App\Models\User;
use App\Support\Sat\CsdCertificateReader;
use Illuminate\Http\Client\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Log\Events\MessageLogged;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;

/**
 * Fase 6.27b (7): CSD enviado directo a Facturapi, revisado en memoria
 * antes, sin guardar archivos ni la contraseña en ningún lado.
 */
const PHASE_627B_CSD_PASSWORD = 'contraseña-del-csd-9X7Q';
const PHASE_627B_CSD_RFC = 'XIA190128J61';

beforeEach(function () {
    config([
        'services.facturapi.base_url' => 'https://example-pac.test/v2',
        'services.facturapi.user_key' => 'sk_user_CSD_UPLOAD_USER_KEY_5e5e',
        'billing.pac_mode' => 'test',
    ]);
    Http::preventStrayRequests();
    Storage::fake('local');
    Storage::fake('public');
});

/**
 * Certificado de prueba en DER y su llave cifrada (PEM), generados en memoria.
 *
 * @return array{0: string, 1: string}
 */
function phase627bCertificate(string $rfc = PHASE_627B_CSD_RFC, ?string $branch = 'Matriz', int $days = 365, int $serial = 0x33303031): array
{
    $config = tempnam(sys_get_temp_dir(), 'ossl');
    file_put_contents($config, "[ req ]\ndistinguished_name = req_dn\n[ req_dn ]\n");
    $options = ['config' => $config, 'private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA, 'digest_alg' => 'sha256'];

    $key = openssl_pkey_new($options);
    $subject = array_filter([
        'commonName' => 'XENON INDUSTRIAL ARTICLES',
        'x500UniqueIdentifier' => "{$rfc} / VAAM7811033G5",
        'organizationalUnitName' => $branch,
    ]);
    $certificate = openssl_csr_sign(openssl_csr_new($subject, $key, $options), null, $key, $days, $options, $serial);
    openssl_x509_export($certificate, $pem);
    openssl_pkey_export($key, $keyPem, PHASE_627B_CSD_PASSWORD, $options);
    @unlink($config);

    return [base64_decode(preg_replace('/-----[^-]+-----|\s/', '', $pem)), $keyPem];
}

function phase627bCsdCompany(string $rfc = PHASE_627B_CSD_RFC): Company
{
    return Company::factory()->withFacturapiOrganization('sk_test_CSD_COMPANY_KEY_0000')->create(['rfc' => $rfc]);
}

function phase627bCsdAdmin(Company $company, UserRole $role = UserRole::Admin): User
{
    return User::factory()->create(['company_id' => $company->id, 'role' => $role]);
}

/** @param array{0: string, 1: string}|null $files */
function phase627bCsdPayload(?array $files = null, array $overrides = []): array
{
    [$cer, $key] = $files ?? phase627bCertificate();

    return array_merge([
        'cer' => UploadedFile::fake()->createWithContent('csd.cer', $cer),
        'key' => UploadedFile::fake()->createWithContent('csd.key', $key),
        'csd_password' => PHASE_627B_CSD_PASSWORD,
    ], $overrides);
}

function phase627bCsdOrganizationResponse(Company $company): array
{
    return [
        'id' => DB::table('facturapi_organizations')->where('company_id', $company->id)->value('organization_id'),
        'legal' => ['tax_id' => PHASE_627B_CSD_RFC, 'legal_name' => 'XENON INDUSTRIAL ARTICLES', 'tax_system' => '601', 'address' => ['zip' => '06010']],
        'certificate' => ['has_certificate' => true, 'serial_number' => '3001', 'expires_at' => '2027-10-29T00:00:00.000Z', 'updated_at' => '2026-10-29T12:00:00.000Z'],
    ];
}

/** Junta todo lo registrado en logs durante la prueba, incluidas las excepciones con su traza. */
function phase627bCapturedLogs(): Closure
{
    $logged = [];
    Event::listen(MessageLogged::class, function (MessageLogged $event) use (&$logged): void {
        $context = $event->context;
        if (isset($context['exception']) && $context['exception'] instanceof Throwable) {
            $context['exception'] = (string) $context['exception'];
        }
        $logged[] = $event->message.' '.json_encode($context, JSON_UNESCAPED_UNICODE);
    });

    return function () use (&$logged): string {
        return implode("\n", $logged);
    };
}

test('el lector obtiene RFC, serie del SAT, vigencia y distingue CSD de FIEL', function () {
    [$cer] = phase627bCertificate();
    [$fiel] = phase627bCertificate(branch: null);

    $certificate = app(CsdCertificateReader::class)->read($cer);

    expect($certificate->rfc)->toBe(PHASE_627B_CSD_RFC)
        ->and($certificate->serialNumber)->toBe('3001')
        ->and($certificate->isCsd())->toBeTrue()
        ->and(app(CsdCertificateReader::class)->read($fiel)->isCsd())->toBeFalse()
        ->and(fn () => app(CsdCertificateReader::class)->read('no es un certificado'))->toThrow(InvalidArgumentException::class);
});

test('el Admin sube el CSD directo a Facturapi sin guardar archivos y queda auditado', function () {
    $company = phase627bCsdCompany();
    $admin = phase627bCsdAdmin($company);
    [$cer, $key] = phase627bCertificate();
    Http::fake(['example-pac.test/v2/organizations/*/certificate' => Http::response(phase627bCsdOrganizationResponse($company))]);

    $response = $this->actingAs($admin, 'api')->post('/api/company/csd', phase627bCsdPayload([$cer, $key]), ['Accept' => 'application/json'])->assertOk();

    $audit = DB::table('facturapi_organization_audits')->first();

    expect($response->json('organization.certificate.has_certificate'))->toBeTrue()
        ->and($response->json('organization.certificate.serial_number'))->toBe('3001')
        ->and($response->getContent())->not->toContain(PHASE_627B_CSD_PASSWORD)
        ->and(DB::table('facturapi_organizations')->where('company_id', $company->id)->value('has_certificate'))->toBe(1)
        ->and($audit->action)->toBe('certificate_uploaded')
        ->and($audit->user_id)->toBe($admin->id)
        ->and(json_decode($audit->metadata, true))->toMatchArray(['serial_number' => '3001', 'rfc' => PHASE_627B_CSD_RFC])
        ->and($audit->metadata)->not->toContain(PHASE_627B_CSD_PASSWORD)
        ->and(Storage::disk('local')->allFiles())->toBe([])
        ->and(Storage::disk('public')->allFiles())->toBe([]);

    Http::assertSent(function (Request $r) use ($cer, $key): bool {
        $parts = collect($r->data())->keyBy('name');

        return $r->method() === 'PUT'
            && $parts['cer']['contents'] === $cer
            && $parts['key']['contents'] === $key
            && $parts['password']['contents'] === PHASE_627B_CSD_PASSWORD;
    });
    Http::assertSentCount(1);
});

test('un certificado ilegible, una FIEL, uno vencido, de otro RFC o sin organización se rechazan sin contactar a Facturapi', function () {
    $company = phase627bCsdCompany();
    $this->actingAs(phase627bCsdAdmin($company), 'api');
    $send = fn (array $payload) => $this->post('/api/company/csd', $payload, ['Accept' => 'application/json']);

    $send(phase627bCsdPayload(['no es un certificado', 'llave']))->assertStatus(422)->assertJsonPath('code', 'COMPANY_CSD_INVALID');
    $send(phase627bCsdPayload(phase627bCertificate(branch: null)))->assertStatus(422)->assertJsonPath('code', 'COMPANY_CSD_IS_FIEL');
    $send(phase627bCsdPayload(phase627bCertificate(rfc: 'ABC010101AB1')))->assertStatus(422)
        ->assertJsonPath('code', 'COMPANY_RFC_CSD_MISMATCH')
        ->assertJsonPath('issues.0.field', 'cer');

    $expired = phase627bCertificate(days: 1);
    $this->travel(2)->days();
    $send(phase627bCsdPayload($expired))->assertStatus(422)->assertJsonPath('code', 'COMPANY_CSD_EXPIRED');
    $this->travelBack();

    $unlinked = Company::factory()->create(['rfc' => 'XIB190128J61']);
    $this->actingAs(phase627bCsdAdmin($unlinked), 'api')
        ->post('/api/company/csd', phase627bCsdPayload(), ['Accept' => 'application/json'])
        ->assertStatus(409)
        ->assertJsonPath('code', 'COMPANY_PAC_ORGANIZATION_MISSING');

    expect(DB::table('facturapi_organization_audits')->count())->toBe(0);
    Http::assertNothingSent();
});

test('valida archivos y contraseña con mensajes en español y rechaza ids en la petición', function () {
    $company = phase627bCsdCompany();
    $this->actingAs(phase627bCsdAdmin($company), 'api');

    $this->post('/api/company/csd', [], ['Accept' => 'application/json'])
        ->assertStatus(422)
        ->assertJsonPath('errors.cer.0', 'Selecciona el archivo .cer del CSD.')
        ->assertJsonPath('errors.key.0', 'Selecciona el archivo .key del CSD.')
        ->assertJsonPath('errors.csd_password.0', 'Escribe la contraseña de la llave privada.');

    $this->post('/api/company/csd', phase627bCsdPayload(overrides: ['cer' => UploadedFile::fake()->create('csd.pdf', 10)]), ['Accept' => 'application/json'])
        ->assertStatus(422)
        ->assertJsonPath('errors.cer.0', 'El certificado debe ser un archivo .cer.');

    $this->post('/api/company/csd', phase627bCsdPayload(overrides: ['key' => UploadedFile::fake()->create('csd.key', 65)]), ['Accept' => 'application/json'])
        ->assertStatus(422)
        ->assertJsonPath('errors.key.0', 'El archivo .key no puede pesar más de 64 KB.');

    $this->post('/api/company/csd', phase627bCsdPayload(overrides: ['organization_id' => 'otra']), ['Accept' => 'application/json'])
        ->assertStatus(422)
        ->assertJsonValidationErrors('organization_id');

    Http::assertNothingSent();
});

test('sólo el Admin sube el CSD', function (UserRole $role) {
    $company = phase627bCsdCompany();

    $this->actingAs(phase627bCsdAdmin($company, $role), 'api')
        ->post('/api/company/csd', phase627bCsdPayload(), ['Accept' => 'application/json'])
        ->assertForbidden();

    Http::assertNothingSent();
})->with([
    'Accountant' => [UserRole::Accountant],
    'Sales' => [UserRole::Sales],
    'Employee' => [UserRole::Employee],
]);

test('el Admin de A sólo afecta la organización de A', function () {
    $companyA = phase627bCsdCompany();
    $companyB = Company::factory()->withFacturapiOrganization()->create();
    $orgA = DB::table('facturapi_organizations')->where('company_id', $companyA->id)->value('organization_id');
    $orgB = DB::table('facturapi_organizations')->where('company_id', $companyB->id)->value('organization_id');
    Http::fake(['example-pac.test/v2/organizations/*/certificate' => Http::response(phase627bCsdOrganizationResponse($companyA))]);

    $this->actingAs(phase627bCsdAdmin($companyA), 'api')
        ->post('/api/company/csd?organization_id='.$orgB, phase627bCsdPayload(), ['Accept' => 'application/json'])
        ->assertStatus(422);
    $this->post('/api/company/csd', phase627bCsdPayload(), ['Accept' => 'application/json'])->assertOk();

    Http::assertSent(fn (Request $r): bool => str_contains($r->url(), "/organizations/{$orgA}/certificate"));
    Http::assertNotSent(fn (Request $r): bool => str_contains($r->url(), $orgB));
    expect(DB::table('facturapi_organizations')->where('company_id', $companyB->id)->value('has_certificate'))->toBe(0)
        ->and(DB::table('facturapi_organization_audits')->where('company_id', $companyB->id)->count())->toBe(0);
});

test('la subida tiene límite de intentos por empresa aunque cambie el usuario', function () {
    $company = phase627bCsdCompany();
    $first = phase627bCsdAdmin($company);
    $second = phase627bCsdAdmin($company);
    $bad = fn () => phase627bCsdPayload(['no es un certificado', 'llave']);

    for ($i = 0; $i < 3; $i++) {
        $this->actingAs($first, 'api')->post('/api/company/csd', $bad(), ['Accept' => 'application/json'])->assertStatus(422);
    }
    for ($i = 0; $i < 2; $i++) {
        $this->actingAs($second, 'api')->post('/api/company/csd', $bad(), ['Accept' => 'application/json'])->assertStatus(422);
    }

    $this->actingAs($second, 'api')->post('/api/company/csd', $bad(), ['Accept' => 'application/json'])->assertStatus(429);
});

test('si Facturapi rechaza el CSD repitiendo la contraseña, no aparece en la respuesta, los logs ni la auditoría', function () {
    $logs = phase627bCapturedLogs();
    $company = phase627bCsdCompany();
    Http::fake(['example-pac.test/v2/*' => Http::response(['code' => 'invalid_password', 'message' => 'La contraseña '.PHASE_627B_CSD_PASSWORD.' no abre la llave'], 400)]);

    $response = $this->actingAs(phase627bCsdAdmin($company), 'api')
        ->post('/api/company/csd', phase627bCsdPayload(), ['Accept' => 'application/json'])
        ->assertStatus(422)
        ->assertJsonPath('code', 'COMPANY_CSD_REJECTED');

    expect($response->getContent())->not->toContain(PHASE_627B_CSD_PASSWORD)
        ->and($logs())->toContain('billing.company.csd_upload_failed')
        ->and($logs())->not->toContain(PHASE_627B_CSD_PASSWORD)
        ->and(DB::table('facturapi_organization_audits')->count())->toBe(0)
        ->and(DB::table('facturapi_organizations')->where('company_id', $company->id)->value('has_certificate'))->toBe(0);
});

test('una excepción durante la subida no deja la contraseña en la respuesta ni en los logs', function () {
    $logs = phase627bCapturedLogs();
    $company = phase627bCsdCompany();
    Http::fake(fn () => throw new RuntimeException('Fallo inesperado del cliente HTTP'));

    $response = $this->actingAs(phase627bCsdAdmin($company), 'api')
        ->post('/api/company/csd', phase627bCsdPayload(), ['Accept' => 'application/json'])
        ->assertStatus(503)
        ->assertJsonPath('code', 'PAC_UNAVAILABLE');

    expect($response->getContent())->not->toContain(PHASE_627B_CSD_PASSWORD)
        ->and($logs())->not->toContain(PHASE_627B_CSD_PASSWORD);
});

test('una excepción no controlada tras la subida se reporta sin la contraseña ni la llave en la traza', function () {
    $logs = phase627bCapturedLogs();
    $company = phase627bCsdCompany();
    [$cer, $key] = phase627bCertificate();
    Http::fake(['example-pac.test/v2/organizations/*/certificate' => Http::response(phase627bCsdOrganizationResponse($company))]);
    FacturapiOrganization::saving(fn () => throw new RuntimeException('Fallo al guardar la organización'));

    $response = $this->actingAs(phase627bCsdAdmin($company), 'api')
        ->post('/api/company/csd', phase627bCsdPayload([$cer, $key]), ['Accept' => 'application/json'])
        ->assertStatus(500);

    $keyBody = trim(preg_replace('/-----[^-]+-----/', '', $key));

    expect($logs())->toContain('Fallo al guardar la organización')
        ->and($logs())->toContain('SensitiveParameterValue')
        ->and($logs())->not->toContain(PHASE_627B_CSD_PASSWORD)
        ->and($logs())->not->toContain(substr($keyBody, 0, 64))
        ->and($response->getContent())->not->toContain(PHASE_627B_CSD_PASSWORD)
        ->and(DB::table('facturapi_organization_audits')->count())->toBe(0);
});
