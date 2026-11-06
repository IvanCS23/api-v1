<?php

use App\Enums\UserRole;
use App\Models\Company;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Password;
use Illuminate\Validation\Rules\Password as PasswordRule;

/**
 * Fase 6.32 (1): PUT /api/me/password. Cualquier rol cambia su propia
 * contraseña con la actual; el token rota, se cierran las sesiones web, se
 * borran los enlaces de recuperación y queda auditado. Un Admin ya no usa
 * los endpoints de administración sobre sí mismo.
 */
const PHASE_632_NEW_PASSWORD = 'Nueva-Contrasena-2026';

function phase632LoggedUser(UserRole $role = UserRole::Sales, array $attributes = []): array
{
    $company = Company::factory()->create();
    $user = User::factory()->create(array_merge(['company_id' => $company->id, 'role' => $role, 'password_changed_at' => '2026-01-01 00:00:00'], $attributes));
    $plain = 'token-plano-'.$user->id.'-'.str_repeat('x', 40);
    $user->forceFill(['api_token' => hash('sha256', $plain)])->save();

    return [$user->fresh(), $plain];
}

function phase632ChangeOwn(string $token, array $payload)
{
    return test()->withHeader('Authorization', 'Bearer '.$token)->putJson('/api/me/password', $payload);
}

beforeEach(function () {
    Carbon::setTestNow('2026-11-06 12:00:00');
});

afterEach(function () {
    Carbon::setTestNow();
});

test('cualquier rol cambia su propia contraseña con la actual y recibe un token nuevo', function (UserRole $role) {
    [$user, $oldToken] = phase632LoggedUser($role, ['must_change_password' => true]);

    $response = phase632ChangeOwn($oldToken, [
        'current_password' => 'password',
        'password' => PHASE_632_NEW_PASSWORD,
        'password_confirmation' => PHASE_632_NEW_PASSWORD,
    ])->assertOk()->assertJsonPath('user.must_change_password', false);

    $newToken = $response->json('token');
    $fresh = $user->fresh();
    $audit = DB::table('audit_logs')->where('user_id', $user->id)->latest('id')->first();

    expect(Hash::check(PHASE_632_NEW_PASSWORD, $fresh->password))->toBeTrue()
        ->and($newToken)->toBeString()->not->toBe($oldToken)
        ->and($fresh->api_token)->toBe(hash('sha256', $newToken))
        ->and($fresh->password_changed_at->toDateTimeString())->toBe('2026-11-06 12:00:00')
        ->and($fresh->must_change_password)->toBeFalse()
        ->and($audit->action)->toBe('password_changed')
        ->and((int) $audit->actor_id)->toBe($user->id)
        ->and(json_decode($audit->metadata, true))->toBe(['source' => 'api_self'])
        ->and($response->getContent())->not->toContain(PHASE_632_NEW_PASSWORD);

    // El token anterior ya no sirve; el nuevo sí.
    app('auth')->forgetGuards();
    test()->withHeader('Authorization', 'Bearer '.$oldToken)->getJson('/api/user')->assertUnauthorized();
    app('auth')->forgetGuards();
    test()->withHeader('Authorization', 'Bearer '.$newToken)->getJson('/api/user')->assertOk();
})->with([UserRole::Admin, UserRole::Accountant, UserRole::Sales, UserRole::Employee]);

test('una contraseña actual incorrecta no cambia nada', function () {
    [$user, $token] = phase632LoggedUser();
    $before = $user->password;

    phase632ChangeOwn($token, ['current_password' => 'incorrecta', 'password' => PHASE_632_NEW_PASSWORD, 'password_confirmation' => PHASE_632_NEW_PASSWORD])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['current_password']);

    expect($user->fresh()->password)->toBe($before)
        ->and($user->fresh()->api_token)->toBe(hash('sha256', $token));
});

test('la contraseña nueva debe confirmarse, cumplir las reglas y ser distinta', function (array $payload, string $field) {
    [, $token] = phase632LoggedUser();

    phase632ChangeOwn($token, array_merge(['current_password' => 'password'], $payload))
        ->assertUnprocessable()
        ->assertJsonValidationErrors([$field]);
})->with([
    'sin confirmar' => [['password' => PHASE_632_NEW_PASSWORD, 'password_confirmation' => 'otra-cosa-123'], 'password'],
    'muy corta' => [['password' => 'corta', 'password_confirmation' => 'corta'], 'password'],
    'igual a la actual' => [['password' => 'password', 'password_confirmation' => 'password'], 'password'],
]);

test('el cambio cierra las sesiones web y borra los enlaces de recuperación pendientes', function () {
    config(['session.driver' => 'database']);
    [$user, $token] = phase632LoggedUser();
    DB::table('sessions')->insert(['id' => 'sesion-web-1', 'user_id' => $user->id, 'ip_address' => '127.0.0.1', 'user_agent' => 'x', 'payload' => 'x', 'last_activity' => time()]);
    $resetToken = Password::broker()->createToken($user);

    phase632ChangeOwn($token, ['current_password' => 'password', 'password' => PHASE_632_NEW_PASSWORD, 'password_confirmation' => PHASE_632_NEW_PASSWORD])->assertOk();

    expect(DB::table('sessions')->where('user_id', $user->id)->count())->toBe(0)
        ->and(DB::table('password_reset_tokens')->where('email', $user->email)->count())->toBe(0)
        ->and(Password::broker()->tokenExists($user->fresh(), $resetToken))->toBeFalse();
});

test('el límite de intentos frena adivinar la contraseña actual', function () {
    [, $token] = phase632LoggedUser();

    foreach (range(1, 5) as $attempt) {
        phase632ChangeOwn($token, ['current_password' => 'incorrecta-'.$attempt, 'password' => PHASE_632_NEW_PASSWORD, 'password_confirmation' => PHASE_632_NEW_PASSWORD])->assertUnprocessable();
    }

    phase632ChangeOwn($token, ['current_password' => 'password', 'password' => PHASE_632_NEW_PASSWORD, 'password_confirmation' => PHASE_632_NEW_PASSWORD])->assertStatus(429);
});

test('sin sesión no se puede usar', function () {
    $this->putJson('/api/me/password', ['current_password' => 'password'])->assertUnauthorized();
});

test('un Admin no cambia ni restablece su propia contraseña por los endpoints de administración', function (string $method, string $suffix) {
    [$admin] = phase632LoggedUser(UserRole::Admin);
    $before = $admin->password;

    $this->actingAs($admin, 'api')
        ->json($method, "/api/users/{$admin->id}/password{$suffix}", ['password' => PHASE_632_NEW_PASSWORD, 'password_confirmation' => PHASE_632_NEW_PASSWORD])
        ->assertUnprocessable()
        ->assertJsonPath('code', 'USE_OWN_PASSWORD_ENDPOINT');

    expect($admin->fresh()->password)->toBe($before);
})->with([
    'cambio' => ['PATCH', ''],
    'restablecimiento' => ['POST', '/reset'],
]);

test('un Admin sigue cambiando la contraseña de otro usuario, con las mismas reglas', function () {
    [$admin] = phase632LoggedUser(UserRole::Admin);
    $sales = User::factory()->create(['company_id' => $admin->company_id, 'role' => UserRole::Sales]);
    $resetToken = Password::broker()->createToken($sales);

    $this->actingAs($admin, 'api')
        ->patchJson("/api/users/{$sales->id}/password", ['password' => 'Otra-Nueva-2026', 'password_confirmation' => 'Otra-Nueva-2026'])
        ->assertOk();

    expect(Hash::check('Otra-Nueva-2026', $sales->fresh()->password))->toBeTrue()
        ->and(Password::broker()->tokenExists($sales->fresh(), $resetToken))->toBeFalse();
});

test('los errores de la contraseña nueva llegan en español con las reglas de producción', function (string $password, string $message) {
    [, $token] = phase632LoggedUser();
    // Las reglas de producción, sin uncompromised() (consultaría un servicio externo).
    PasswordRule::defaults(fn () => PasswordRule::min(12)->mixedCase()->letters()->numbers()->symbols());

    phase632ChangeOwn($token, ['current_password' => 'password', 'password' => $password, 'password_confirmation' => $password])
        ->assertUnprocessable()
        ->assertJsonPath('errors.password.0', $message);
})->with([
    'corta' => ['Ab1-', 'La contraseña debe tener al menos 12 caracteres.'],
    'sin mayúsculas' => ['minusculas-2026-x', 'La contraseña debe tener mayúsculas y minúsculas.'],
    'sin números' => ['Sin-Numeros-Aqui', 'La contraseña debe tener al menos un número.'],
    'sin símbolos' => ['SinSimbolos2026x', 'La contraseña debe tener al menos un símbolo.'],
]);

test('la contraseña actual vacía o una confirmación distinta se explican en español', function () {
    [, $token] = phase632LoggedUser();

    phase632ChangeOwn($token, ['current_password' => '', 'password' => PHASE_632_NEW_PASSWORD, 'password_confirmation' => 'otra'])
        ->assertUnprocessable()
        ->assertJsonPath('errors.current_password.0', 'Escribe tu contraseña actual.')
        ->assertJsonPath('errors.password.0', 'La confirmación no coincide con la contraseña nueva.');
});
