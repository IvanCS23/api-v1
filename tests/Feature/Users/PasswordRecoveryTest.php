<?php

use App\Enums\UserRole;
use App\Enums\UserStatus;
use App\Http\Controllers\Api\PasswordRecoveryController;
use App\Models\Company;
use App\Models\User;
use App\Notifications\ResetPasswordNotification;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Password;

/**
 * Fase 6.32 (3): recuperación de contraseña por correo hacia Next.js.
 */
const PHASE_632_RESET_PASSWORD = 'Restablecida-2026A';

beforeEach(function () {
    config(['app.frontend_url' => 'https://app.example.test']);
    Notification::fake();
});

function phase632RecoveryUser(array $attributes = []): User
{
    $company = Company::factory()->create();
    $user = User::factory()->create(array_merge(['company_id' => $company->id, 'role' => UserRole::Sales, 'email' => 'persona@example.com'], $attributes));
    $user->forceFill(['api_token' => hash('sha256', 'token-previo-'.$user->id)])->save();

    return $user->fresh();
}

function phase632Reset(string $email, string $token, string $password = PHASE_632_RESET_PASSWORD)
{
    return test()->postJson('/api/password/reset', ['email' => $email, 'token' => $token, 'password' => $password, 'password_confirmation' => $password]);
}

test('la solicitud responde igual exista el correo, no exista o el usuario esté inactivo', function () {
    $active = phase632RecoveryUser();
    phase632RecoveryUser(['email' => 'inactivo@example.com', 'status' => UserStatus::Inactive]);

    $bodies = [];

    foreach (['persona@example.com', 'nadie@example.com', 'inactivo@example.com'] as $email) {
        $response = $this->postJson('/api/password/forgot', ['email' => $email])->assertStatus(202);
        $bodies[] = $response->getContent();
    }

    expect(array_unique($bodies))->toHaveCount(1)
        ->and(json_decode($bodies[0], true))->toBe(['message' => PasswordRecoveryController::FORGOT_MESSAGE]);

    Notification::assertSentTo($active, ResetPasswordNotification::class);
    Notification::assertCount(1);
    expect(User::where('email', 'inactivo@example.com')->first()->status)->toBe(UserStatus::Inactive);
});

test('el enlace lleva a Next.js con el token después de # y la notificación va en cola', function () {
    $user = phase632RecoveryUser();

    $this->postJson('/api/password/forgot', ['email' => $user->email])->assertStatus(202);

    Notification::assertSentTo($user, ResetPasswordNotification::class, function (ResetPasswordNotification $notification) use ($user): bool {
        $url = $notification->toMail($user)->actionUrl;
        [$base, $fragment] = explode('#', $url, 2);
        parse_str($fragment, $params);

        return $base === 'https://app.example.test/restablecer-contrasena'
            && ! str_contains($base, '?')
            && $params['email'] === $user->email
            && Password::broker()->tokenExists($user, $params['token'])
            && $notification instanceof ShouldQueue
            && str_contains(implode(' ', $notification->toMail($user)->introLines), 'Recibimos una solicitud');
    });
});

test('el enlace restablece una sola vez, revoca el token y las sesiones y audita', function () {
    config(['session.driver' => 'database']);
    $user = phase632RecoveryUser(['must_change_password' => true]);
    DB::table('sessions')->insert(['id' => 'sesion-web', 'user_id' => $user->id, 'ip_address' => '127.0.0.1', 'user_agent' => 'x', 'payload' => 'x', 'last_activity' => time()]);
    $token = Password::broker()->createToken($user);

    phase632Reset($user->email, $token)->assertOk();

    $fresh = $user->fresh();
    $audit = DB::table('audit_logs')->where('user_id', $user->id)->latest('id')->first();

    expect(Hash::check(PHASE_632_RESET_PASSWORD, $fresh->password))->toBeTrue()
        ->and($fresh->api_token)->toBeNull()
        ->and($fresh->must_change_password)->toBeFalse()
        ->and($fresh->password_changed_at)->not->toBeNull()
        ->and(DB::table('sessions')->where('user_id', $user->id)->count())->toBe(0)
        ->and($audit->action)->toBe('password_changed')
        ->and($audit->actor_id)->toBeNull()
        ->and(json_decode($audit->metadata, true))->toBe(['source' => 'api_password_reset'])
        ->and($audit->metadata)->not->toContain($token);

    phase632Reset($user->email, $token, 'Otra-Vez-2026A')
        ->assertUnprocessable()
        ->assertJsonPath('code', PasswordRecoveryController::INVALID_CODE);
    expect(Hash::check(PHASE_632_RESET_PASSWORD, $user->fresh()->password))->toBeTrue();
});

test('un enlace vencido, ajeno o inventado no sirve', function (string $case) {
    $user = phase632RecoveryUser();
    $other = phase632RecoveryUser(['email' => 'otra@example.com']);
    $token = Password::broker()->createToken($user);
    $otherToken = Password::broker()->createToken($other);

    if ($case === 'vencido') {
        $this->travel(31)->minutes();
    }

    $response = match ($case) {
        'vencido' => phase632Reset($user->email, $token),
        'de otro correo' => phase632Reset($user->email, $otherToken),
        'inventado' => phase632Reset($user->email, str_repeat('a', 64)),
        'correo inexistente' => phase632Reset('nadie@example.com', $token),
    };

    $response->assertUnprocessable()->assertJsonPath('code', PasswordRecoveryController::INVALID_CODE);
    expect(Hash::check('password', $user->fresh()->password))->toBeTrue();
})->with(['vencido', 'de otro correo', 'inventado', 'correo inexistente']);

test('un usuario inactivo no restablece con un enlace previo ni se reactiva', function () {
    $user = phase632RecoveryUser();
    $token = Password::broker()->createToken($user);
    $user->forceFill(['status' => UserStatus::Inactive])->save();

    phase632Reset($user->email, $token)->assertUnprocessable()->assertJsonPath('code', PasswordRecoveryController::INVALID_CODE);

    expect($user->fresh()->status)->toBe(UserStatus::Inactive)
        ->and(Hash::check('password', $user->fresh()->password))->toBeTrue();
});

test('un enlace viejo deja de servir al cambiar la contraseña o el correo', function (string $case) {
    $user = phase632RecoveryUser();
    $token = Password::broker()->createToken($user);
    $admin = User::factory()->create(['company_id' => $user->company_id, 'role' => UserRole::Admin]);

    match ($case) {
        'cambio de la propia contraseña' => $this->actingAs($user, 'api')->putJson('/api/me/password', ['current_password' => 'password', 'password' => 'Propia-Nueva-2026', 'password_confirmation' => 'Propia-Nueva-2026'])->assertOk(),
        'un Admin cambia su contraseña' => $this->actingAs($admin, 'api')->patchJson("/api/users/{$user->id}/password", ['password' => 'Admin-Nueva-2026', 'password_confirmation' => 'Admin-Nueva-2026'])->assertOk(),
        'un Admin cambia su correo' => $this->actingAs($admin, 'api')->putJson("/api/users/{$user->id}", ['email' => 'nuevo-correo@example.com'])->assertOk(),
    };

    app('auth')->forgetGuards();
    $email = $user->fresh()->email;

    phase632Reset($email, $token)->assertUnprocessable()->assertJsonPath('code', PasswordRecoveryController::INVALID_CODE);
    phase632Reset('persona@example.com', $token)->assertUnprocessable();
    expect(DB::table('password_reset_tokens')->count())->toBe(0);
})->with(['cambio de la propia contraseña', 'un Admin cambia su contraseña', 'un Admin cambia su correo']);

test('límites: 1 por correo por minuto, 5 por correo por hora y 5 por IP cada 15 minutos', function () {
    phase632RecoveryUser();

    $this->postJson('/api/password/forgot', ['email' => 'nadie@example.com'])->assertStatus(202);
    $this->postJson('/api/password/forgot', ['email' => 'nadie@example.com'])->assertStatus(429);
    $this->postJson('/api/password/forgot', ['email' => 'NADIE@example.com'])->assertStatus(429);

    foreach (range(1, 4) as $minute) {
        $this->travel(2)->minutes();
        $this->withServerVariables(['REMOTE_ADDR' => '10.0.0.'.$minute])->postJson('/api/password/forgot', ['email' => 'nadie@example.com'])->assertStatus(202);
    }

    $this->travel(2)->minutes();
    $this->withServerVariables(['REMOTE_ADDR' => '10.0.0.99'])->postJson('/api/password/forgot', ['email' => 'nadie@example.com'])->assertStatus(429);

    foreach (range(1, 5) as $i) {
        $this->withServerVariables(['REMOTE_ADDR' => '10.9.9.9'])->postJson('/api/password/forgot', ['email' => "otro{$i}@example.com"])->assertStatus(202);
    }

    $this->withServerVariables(['REMOTE_ADDR' => '10.9.9.9'])->postJson('/api/password/forgot', ['email' => 'otro6@example.com'])->assertStatus(429);
});

test('las reglas de la contraseña nueva se aplican al restablecer', function () {
    $user = phase632RecoveryUser();
    $token = Password::broker()->createToken($user);

    $this->postJson('/api/password/reset', ['email' => $user->email, 'token' => $token, 'password' => 'corta', 'password_confirmation' => 'corta'])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['password']);

    expect(Password::broker()->tokenExists($user, $token))->toBeTrue();
});

test('los errores de validación llegan en español', function () {
    $this->postJson('/api/password/forgot', ['email' => 'no-es-correo'])
        ->assertUnprocessable()
        ->assertJsonPath('errors.email.0', 'Escribe un correo válido.');

    $this->postJson('/api/password/reset', ['email' => '', 'token' => '', 'password' => 'Nueva-Segura-2026', 'password_confirmation' => 'Nueva-Segura-2026'])
        ->assertUnprocessable()
        ->assertJsonPath('errors.token.0', PasswordRecoveryController::INVALID_MESSAGE)
        ->assertJsonPath('errors.email.0', PasswordRecoveryController::INVALID_MESSAGE);

    Notification::assertNothingSent();
});

/** Fase 6.32 (D3): N solicitudes del mismo correo, cada una 2 minutos después y desde otra IP. */
function phase632ForgotSpaced(string $email, int $times, string $ipPrefix): array
{
    $statuses = [];

    foreach (range(1, $times) as $i) {
        $statuses[] = test()->withServerVariables(['REMOTE_ADDR' => $ipPrefix.$i])
            ->postJson('/api/password/forgot', ['email' => $email])->status();
        test()->travel(2)->minutes();
    }

    return $statuses;
}

test('5 por correo por hora: la sexta da 429 igual exista o no el correo, y la hora siguiente vuelve a servir', function () {
    $user = phase632RecoveryUser();

    expect(phase632ForgotSpaced('persona@example.com', 5, '10.1.0.'))->toBe([202, 202, 202, 202, 202])
        ->and(phase632ForgotSpaced('nadie@example.com', 5, '10.2.0.'))->toBe([202, 202, 202, 202, 202]);

    $existing = $this->withServerVariables(['REMOTE_ADDR' => '10.1.1.1'])->postJson('/api/password/forgot', ['email' => 'persona@example.com']);
    $missing = $this->withServerVariables(['REMOTE_ADDR' => '10.2.1.1'])->postJson('/api/password/forgot', ['email' => 'nadie@example.com']);

    $existing->assertStatus(429);
    $missing->assertStatus(429);
    expect($existing->getContent())->toBe($missing->getContent())
        ->and($existing->json('message'))->toBe('Demasiadas solicitudes. Espera un momento antes de intentar de nuevo.')
        ->and($existing->headers->get('X-RateLimit-Limit'))->toBe($missing->headers->get('X-RateLimit-Limit'));
    Notification::assertSentToTimes($user, ResetPasswordNotification::class, 5);

    $this->travel(61)->minutes();
    $this->withServerVariables(['REMOTE_ADDR' => '10.1.2.1'])->postJson('/api/password/forgot', ['email' => 'persona@example.com'])->assertStatus(202);
    Notification::assertSentToTimes($user, ResetPasswordNotification::class, 6);
});

test('5 por IP cada 15 minutos: la sexta da 429 igual exista o no el correo, y a los 15 minutos vuelve a servir', function () {
    phase632RecoveryUser();
    phase632RecoveryUser(['email' => 'otra@example.com']);
    $fromIp = fn (string $email) => $this->withServerVariables(['REMOTE_ADDR' => '10.3.3.3'])->postJson('/api/password/forgot', ['email' => $email]);

    foreach (['persona@example.com', 'nadie1@example.com', 'nadie2@example.com', 'nadie3@example.com', 'nadie4@example.com'] as $email) {
        $fromIp($email)->assertStatus(202);
    }

    $existing = $fromIp('otra@example.com')->assertStatus(429);
    $missing = $fromIp('nadie5@example.com')->assertStatus(429);
    expect($existing->getContent())->toBe($missing->getContent())
        ->and($existing->headers->get('X-RateLimit-Limit'))->toBe($missing->headers->get('X-RateLimit-Limit'));
    Notification::assertCount(1);

    $this->travel(16)->minutes();
    $fromIp('otra@example.com')->assertStatus(202);
    Notification::assertCount(2);
});
