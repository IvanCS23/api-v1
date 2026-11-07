<?php

use App\Enums\UserRole;
use App\Models\Company;
use App\Models\User;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Password as PasswordBroker;
use Illuminate\Testing\TestResponse;
use Illuminate\Validation\Rules\Password;

/**
 * Fase 6.32 (corrección 6): con las reglas de producción activas (las de
 * AppServiceProvider con APP_ENV=production; el servicio de contraseñas
 * filtradas simulado, sin red), una contraseña de 8 caracteres se rechaza y
 * una de 12 con mayúsculas, minúsculas, números y símbolos se acepta en los
 * cuatro caminos: el cambio propio, la recuperación, la contraseña temporal
 * al crear un usuario y el cambio que hace el Admin.
 *
 * El texto de ayuda de Next.js (src/lib/passwordRules.ts) describe estas
 * mismas reglas; su prueba está en el frontend.
 */
const PHASE_632_EIGHT = 'Abc-1234';
const PHASE_632_TWELVE = 'Abcdef-12345';

beforeEach(function () {
    app()['env'] = 'production';
    Http::preventStrayRequests();
    Http::fake(['api.pwnedpasswords.com/*' => Http::response('')]);
    Notification::fake();
});

function phase632RulesFixture(): array
{
    $company = Company::factory()->create();
    $admin = User::factory()->create(['company_id' => $company->id, 'role' => UserRole::Admin]);
    $user = User::factory()->create(['company_id' => $company->id, 'role' => UserRole::Sales, 'email' => 'reglas@example.com']);
    $plain = 'token-reglas-'.$user->id.'-'.str_repeat('r', 40);
    $user->forceFill(['api_token' => hash('sha256', $plain)])->save();

    return [$admin, $user->fresh(), $plain];
}

/** @return array{0: TestResponse, 1: ?User} */
function phase632SetPasswordVia(string $path, string $password): array
{
    [$admin, $user, $token] = phase632RulesFixture();
    $pair = ['password' => $password, 'password_confirmation' => $password];

    return match ($path) {
        'cambio propio' => [test()->withHeader('Authorization', 'Bearer '.$token)->putJson('/api/me/password', ['current_password' => 'password', ...$pair]), $user],
        'recuperación' => [test()->postJson('/api/password/reset', ['email' => $user->email, 'token' => PasswordBroker::broker()->createToken($user), ...$pair]), $user],
        'temporal al crear' => [test()->actingAs($admin, 'api')->postJson('/api/users', ['name' => 'Nueva', 'email' => 'nueva-reglas@example.com', 'role' => UserRole::Sales->value, 'must_change_password' => true, ...$pair]), null],
        'endpoint del Admin' => [test()->actingAs($admin, 'api')->patchJson("/api/users/{$user->id}/password", [...$pair, 'must_change_password' => true]), $user],
    };
}

test('las reglas de producción son las que describe el texto de ayuda', function () {
    $rules = Password::default()->appliedRules();

    expect($rules['min'])->toBe(12)
        ->and($rules['mixedCase'])->toBeTrue()
        ->and($rules['letters'])->toBeTrue()
        ->and($rules['numbers'])->toBeTrue()
        ->and($rules['symbols'])->toBeTrue()
        ->and($rules['uncompromised'])->toBeTrue();
});

test('8 caracteres se rechazan', function (string $path) {
    [$response, $user] = phase632SetPasswordVia($path, PHASE_632_EIGHT);

    $response->assertUnprocessable()->assertJsonPath('errors.password.0', 'La contraseña debe tener al menos 12 caracteres.');

    if ($user) {
        expect(Hash::check('password', $user->fresh()->password))->toBeTrue();
    } else {
        expect(User::where('email', 'nueva-reglas@example.com')->exists())->toBeFalse();
    }
})->with(['cambio propio', 'recuperación', 'temporal al crear', 'endpoint del Admin']);

test('12 con mayúsculas, minúsculas, números y símbolos se aceptan', function (string $path) {
    [$response, $user] = phase632SetPasswordVia($path, PHASE_632_TWELVE);

    $response->assertSuccessful();
    $saved = $user ? $user->fresh() : User::where('email', 'nueva-reglas@example.com')->first();
    expect(Hash::check(PHASE_632_TWELVE, $saved->password))->toBeTrue();
    Http::assertSent(fn ($request) => str_starts_with($request->url(), 'https://api.pwnedpasswords.com/range/'));
})->with(['cambio propio', 'recuperación', 'temporal al crear', 'endpoint del Admin']);
