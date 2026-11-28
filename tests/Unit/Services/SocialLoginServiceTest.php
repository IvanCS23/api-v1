<?php

use App\Enums\SocialProvider;
use App\Enums\UserRole;
use App\Enums\UserStatus;
use App\Exceptions\SocialLoginException;
use App\Models\Company;
use App\Models\SocialAccount;
use App\Models\User;
use App\Services\Auth\SocialLoginService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Socialite\Two\User as SocialiteTwoUser;
use SocialiteProviders\Microsoft\MicrosoftUser;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

/*
 * Sin llamadas reales: los usuarios de Google y Microsoft se arman a mano con
 * las mismas clases que devuelve Socialite.
 */

/** Inquilino de las cuentas personales de Microsoft. */
function socialSvcConsumerTenant(): string
{
    return '9188040d-6c67-4c5b-b112-36a304b66dad';
}

function socialSvcClient(array $attributes = []): User
{
    return User::factory()->client()->create(array_merge([
        'company_id' => Company::factory()->create()->id,
        'email' => 'cliente@example.com',
    ], $attributes));
}

function socialSvcGoogleUser(string $id, ?string $email, mixed $verified): SocialiteTwoUser
{
    return (new SocialiteTwoUser)
        ->setRaw(['sub' => $id, 'email' => $email, 'email_verified' => $verified])
        ->map(['id' => $id, 'email' => $email, 'name' => 'Cliente Google']);
}

function socialSvcJwt(array $claims): string
{
    $encode = fn (array $part): string => rtrim(strtr(base64_encode(json_encode($part)), '+/', '-_'), '=');

    return $encode(['alg' => 'RS256', 'typ' => 'JWT']).'.'.$encode($claims).'.firma';
}

/**
 * El correo de Graph (`mail`) es distinto a propósito: el servicio solo debe
 * confiar en el del id_token.
 */
function socialSvcMicrosoftUser(string $id, ?array $claims): MicrosoftUser
{
    $user = (new MicrosoftUser)
        ->setRaw(['id' => $id, 'mail' => 'graph@otro-dominio.com'])
        ->map(['id' => $id, 'email' => 'graph@otro-dominio.com', 'name' => 'Cliente Microsoft']);

    $user->setAccessTokenResponseBody(array_filter([
        'access_token' => 'token-de-acceso',
        'id_token' => $claims === null ? null : socialSvcJwt($claims),
    ]));

    return $user;
}

function socialSvcReason(callable $callback): ?string
{
    try {
        $callback();
    } catch (SocialLoginException $e) {
        return $e->reason;
    }

    return null;
}

// --- Google ---------------------------------------------------------------

test('Google con email_verified true encuentra al Client y lo vincula', function () {
    $client = socialSvcClient();
    $service = app(SocialLoginService::class);

    $user = $service->resolveUser(SocialProvider::Google, socialSvcGoogleUser('g-1', 'Cliente@Example.com', true));

    expect($user->is($client))->toBeTrue();

    $link = SocialAccount::query()->sole();
    expect($link->user_id)->toBe($client->id)
        ->and($link->provider)->toBe('google')
        ->and($link->provider_user_id)->toBe('g-1')
        ->and($link->email)->toBe('cliente@example.com')
        ->and($link->last_used_at)->not->toBeNull();
});

test('Google con email_verified false o ausente se rechaza', function (mixed $verified) {
    socialSvcClient();
    $service = app(SocialLoginService::class);

    expect(socialSvcReason(fn () => $service->resolveUser(SocialProvider::Google, socialSvcGoogleUser('g-1', 'cliente@example.com', $verified))))
        ->toBe('email_not_verified')
        ->and(SocialAccount::query()->count())->toBe(0);
})->with([
    'false' => [false],
    'cadena false' => ['false'],
    'ausente' => [null],
]);

// --- Microsoft ------------------------------------------------------------

test('Microsoft con cuenta personal usa el correo del id_token', function () {
    $client = socialSvcClient();
    $service = app(SocialLoginService::class);
    $socialUser = socialSvcMicrosoftUser('ms-1', ['email' => 'cliente@example.com', 'tid' => socialSvcConsumerTenant()]);

    expect($service->verifiedEmail(SocialProvider::Microsoft, $socialUser))->toBe('cliente@example.com')
        ->and($service->resolveUser(SocialProvider::Microsoft, $socialUser)->is($client))->toBeTrue();
});

test('Microsoft con cuenta de empresa y xms_edov verdadero entra', function (mixed $edov) {
    $client = socialSvcClient();
    $service = app(SocialLoginService::class);
    $socialUser = socialSvcMicrosoftUser('ms-1', ['email' => 'cliente@example.com', 'tid' => 'inquilino-de-empresa', 'xms_edov' => $edov]);

    expect($service->resolveUser(SocialProvider::Microsoft, $socialUser)->is($client))->toBeTrue();
})->with([
    'true' => [true],
    'cadena true' => ['true'],
    'uno' => [1],
]);

test('Microsoft con cuenta de empresa sin xms_edov se rechaza', function (array $claims) {
    socialSvcClient();
    $service = app(SocialLoginService::class);
    $socialUser = socialSvcMicrosoftUser('ms-1', $claims);

    expect($service->verifiedEmail(SocialProvider::Microsoft, $socialUser))->toBeNull()
        ->and(socialSvcReason(fn () => $service->resolveUser(SocialProvider::Microsoft, $socialUser)))->toBe('email_not_verified');
})->with([
    'sin la notificación' => [['email' => 'cliente@example.com', 'tid' => 'inquilino-de-empresa']],
    'xms_edov falso' => [['email' => 'cliente@example.com', 'tid' => 'inquilino-de-empresa', 'xms_edov' => false]],
    'sin tid' => [['email' => 'cliente@example.com']],
]);

test('Microsoft sin id_token (o ilegible) se rechaza aunque Graph traiga correo', function () {
    socialSvcClient(['email' => 'graph@otro-dominio.com']);
    $service = app(SocialLoginService::class);

    $withoutIdToken = socialSvcMicrosoftUser('ms-1', null);
    $broken = socialSvcMicrosoftUser('ms-2', null);
    $broken->setAccessTokenResponseBody(['id_token' => 'no-es-un-jwt']);

    expect(socialSvcReason(fn () => $service->resolveUser(SocialProvider::Microsoft, $withoutIdToken)))->toBe('email_not_verified')
        ->and(socialSvcReason(fn () => $service->resolveUser(SocialProvider::Microsoft, $broken)))->toBe('email_not_verified');
});

// --- Usuario --------------------------------------------------------------

test('un correo verificado sin usuario en FacturaPro no crea cuentas', function () {
    $service = app(SocialLoginService::class);

    expect(socialSvcReason(fn () => $service->resolveUser(SocialProvider::Google, socialSvcGoogleUser('g-1', 'nadie@example.com', true))))
        ->toBe('account_not_found')
        ->and(User::query()->count())->toBe(0)
        ->and(SocialAccount::query()->count())->toBe(0);
});

test('solo el rol Client puede entrar', function (UserRole $role) {
    socialSvcClient(['role' => $role]);
    $service = app(SocialLoginService::class);

    expect(socialSvcReason(fn () => $service->resolveUser(SocialProvider::Google, socialSvcGoogleUser('g-1', 'cliente@example.com', true))))
        ->toBe('role_not_allowed')
        ->and(SocialAccount::query()->count())->toBe(0);
})->with([
    'Admin' => [UserRole::Admin],
    'Accountant' => [UserRole::Accountant],
    'Sales' => [UserRole::Sales],
    'Employee' => [UserRole::Employee],
]);

test('un Client inactivo no puede entrar', function () {
    socialSvcClient(['status' => UserStatus::Inactive]);
    $service = app(SocialLoginService::class);

    expect(socialSvcReason(fn () => $service->resolveUser(SocialProvider::Google, socialSvcGoogleUser('g-1', 'cliente@example.com', true))))
        ->toBe('account_inactive');
});

test('si el correo coincide con dos usuarios no se adivina la cuenta', function () {
    // SQLite distingue mayúsculas en el índice único; MySQL no lo permitiría.
    socialSvcClient(['email' => 'Cliente@example.com']);
    socialSvcClient(['email' => 'cliente@example.com']);
    $service = app(SocialLoginService::class);

    expect(socialSvcReason(fn () => $service->resolveUser(SocialProvider::Google, socialSvcGoogleUser('g-1', 'cliente@example.com', true))))
        ->toBe('account_not_found')
        ->and(SocialAccount::query()->count())->toBe(0);
});

test('el segundo login se resuelve por social_accounts aunque cambie el correo', function () {
    $client = socialSvcClient();
    $service = app(SocialLoginService::class);
    $service->resolveUser(SocialProvider::Google, socialSvcGoogleUser('g-1', 'cliente@example.com', true));

    // Mismo id de Google, otro correo (y ya no verificado): sigue siendo él.
    $user = $service->resolveUser(SocialProvider::Google, socialSvcGoogleUser('g-1', 'nuevo@gmail.com', false));

    expect($user->is($client))->toBeTrue()
        ->and(SocialAccount::query()->count())->toBe(1)
        ->and(SocialAccount::query()->sole()->provider_user_id)->toBe('g-1');
});

test('el vínculo existente también respeta rol y estado', function () {
    $client = socialSvcClient();
    $service = app(SocialLoginService::class);
    $service->resolveUser(SocialProvider::Google, socialSvcGoogleUser('g-1', 'cliente@example.com', true));

    $client->forceFill(['status' => UserStatus::Inactive])->save();

    expect(socialSvcReason(fn () => $service->resolveUser(SocialProvider::Google, socialSvcGoogleUser('g-1', 'cliente@example.com', true))))
        ->toBe('account_inactive');
});

test('un id vacío del proveedor se rechaza', function () {
    socialSvcClient();
    $service = app(SocialLoginService::class);

    expect(socialSvcReason(fn () => $service->resolveUser(SocialProvider::Google, socialSvcGoogleUser('', 'cliente@example.com', true))))
        ->toBe('provider_error');
});

// --- state y código ---------------------------------------------------------

test('el state sirve una sola vez y solo para su proveedor', function () {
    $service = app(SocialLoginService::class);
    $state = $service->createState(SocialProvider::Google);
    $other = $service->createState(SocialProvider::Google);

    expect($state)->toHaveLength(40)
        ->and($service->consumeState(SocialProvider::Google, $state))->toBeTrue()
        ->and($service->consumeState(SocialProvider::Google, $state))->toBeFalse()
        // Usarlo con otro proveedor también lo consume.
        ->and($service->consumeState(SocialProvider::Microsoft, $other))->toBeFalse()
        ->and($service->consumeState(SocialProvider::Google, $other))->toBeFalse()
        ->and($service->consumeState(SocialProvider::Google, ''))->toBeFalse()
        ->and($service->consumeState(SocialProvider::Google, null))->toBeFalse()
        ->and($service->consumeState(SocialProvider::Google, ['arreglo']))->toBeFalse();
});

test('el código de login sirve una sola vez', function () {
    $client = socialSvcClient();
    $service = app(SocialLoginService::class);
    $code = $service->createLoginCode($client);

    expect($code)->toHaveLength(64)
        ->and($service->consumeLoginCode($code)->is($client))->toBeTrue()
        ->and(socialSvcReason(fn () => $service->consumeLoginCode($code)))->toBe('invalid_code');
});

test('el código de login expira a los 60 segundos', function () {
    $client = socialSvcClient();
    $service = app(SocialLoginService::class);
    $fresh = $service->createLoginCode($client);
    $expired = $service->createLoginCode($client);

    test()->travel(59)->seconds();
    expect($service->consumeLoginCode($fresh)->is($client))->toBeTrue();

    test()->travel(2)->seconds();
    expect(socialSvcReason(fn () => $service->consumeLoginCode($expired)))->toBe('invalid_code');
});

test('el canje vuelve a revisar al usuario', function () {
    $client = socialSvcClient();
    $service = app(SocialLoginService::class);
    $code = $service->createLoginCode($client);

    $client->forceFill(['status' => UserStatus::Inactive])->save();

    expect(socialSvcReason(fn () => $service->consumeLoginCode($code)))->toBe('account_inactive');
});
