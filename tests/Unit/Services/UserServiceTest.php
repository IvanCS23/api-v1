<?php

use App\Enums\AuditAction;
use App\Enums\UserRole;
use App\Enums\UserStatus;
use App\Exceptions\CannotDeactivateSelfException;
use App\Exceptions\CompanyAdminViolationException;
use App\Models\AuditLog;
use App\Models\Company;
use App\Models\User;
use App\Services\Users\UserService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

// tests/Pest.php solo extiende TestCase en Feature: aquí se declara a mano.
uses(TestCase::class, RefreshDatabase::class);

/*
 * Las reglas del guard por HTTP (último Admin, propio rol/estado, otro
 * Admin) las cubre UserAdministrationTest; aquí se prueba que el servicio
 * las respeta y lo que no tenía prueba: hash y avatar al crear, tokens de
 * recuperación al cambiar el correo, auditoría del estado y la
 * autodesactivación.
 */
function userServiceMember(Company $company, UserRole $role, UserStatus $status = UserStatus::Active): User
{
    return User::factory()->create(['company_id' => $company->id, 'role' => $role, 'status' => $status]);
}

/**
 * Archivo de imagen falso sin la extensión GD (fake()->image() la exige):
 * el servicio no valida el tipo, eso lo hacen Store/UpdateUserRequest.
 */
function userServiceAvatar(string $name = 'avatar.png'): UploadedFile
{
    return UploadedFile::fake()->create($name, 10, 'image/png');
}

/** Request como el del controlador: AuditLogger toma de él actor, IP y user agent. */
function userServiceRequest(User $actor): Request
{
    $request = Request::create('/api/users', 'PUT');
    $request->setUserResolver(fn () => $actor);

    return $request;
}

test('create guarda la contraseña con hash y el avatar en el disco public', function () {
    $disk = Storage::fake('public');
    $company = Company::factory()->create();

    $user = app(UserService::class)->create([
        'name' => 'Usuario nuevo',
        'email' => 'nuevo@example.com',
        'password' => 'contraseña-segura-1',
        'role' => UserRole::Sales->value,
    ], $company->id, userServiceAvatar());

    expect($user->exists)->toBeTrue()
        ->and($user->company_id)->toBe($company->id)
        ->and($user->status)->toBe(UserStatus::Active)
        ->and($user->password)->not->toBe('contraseña-segura-1')
        ->and(Hash::check('contraseña-segura-1', $user->password))->toBeTrue()
        ->and($user->avatar)->toStartWith('avatars/')
        ->and($user->relationLoaded('company'))->toBeTrue();

    $disk->assertExists($user->avatar);
});

test('create sin avatar deja avatar en null', function () {
    $company = Company::factory()->create();

    $user = app(UserService::class)->create([
        'name' => 'Sin avatar',
        'email' => 'sin-avatar@example.com',
        'password' => 'contraseña-segura-1',
        'role' => UserRole::Sales->value,
    ], $company->id);

    expect($user->avatar)->toBeNull();
});

test('update lanza CompanyAdminViolationException con el payload del guard y no guarda', function () {
    $company = Company::factory()->create();
    $admin = userServiceMember($company, UserRole::Admin);

    expect(fn () => app(UserService::class)->update($admin, $admin, ['role' => UserRole::Sales->value], null, userServiceRequest($admin)))
        ->toThrow(function (CompanyAdminViolationException $e): void {
            expect($e->violation)->toBe([
                'code' => 'USER_LAST_ADMIN',
                'message' => 'La empresa debe conservar al menos un administrador activo.',
            ]);
        });

    expect($admin->fresh()->role)->toBe(UserRole::Admin);
});

test('update con cambio de email invalida los tokens de recuperación del correo anterior', function () {
    $company = Company::factory()->create();
    $admin = userServiceMember($company, UserRole::Admin);
    $user = userServiceMember($company, UserRole::Sales);
    $oldEmail = $user->email;
    DB::table('password_reset_tokens')->insert(['email' => $oldEmail, 'token' => Hash::make('token'), 'created_at' => now()]);

    $updated = app(UserService::class)->update($admin, $user, ['email' => 'nuevo-correo@example.com'], null, userServiceRequest($admin));

    expect($updated->email)->toBe('nuevo-correo@example.com')
        ->and(DB::table('password_reset_tokens')->where('email', $oldEmail)->count())->toBe(0);
});

test('update sin cambio de email conserva los tokens de recuperación', function () {
    $company = Company::factory()->create();
    $admin = userServiceMember($company, UserRole::Admin);
    $user = userServiceMember($company, UserRole::Sales);
    DB::table('password_reset_tokens')->insert(['email' => $user->email, 'token' => Hash::make('token'), 'created_at' => now()]);

    app(UserService::class)->update($admin, $user, ['name' => 'Otro nombre'], null, userServiceRequest($admin));

    expect(DB::table('password_reset_tokens')->where('email', $user->email)->count())->toBe(1);
});

test('update reemplaza el avatar y borra el anterior', function () {
    $disk = Storage::fake('public');
    $company = Company::factory()->create();
    $admin = userServiceMember($company, UserRole::Admin);
    $user = userServiceMember($company, UserRole::Sales);
    $old = userServiceAvatar('viejo.png')->store('avatars', 'public');
    $user->forceFill(['avatar' => $old])->save();

    $updated = app(UserService::class)->update($admin, $user, [], userServiceAvatar('nuevo.png'), userServiceRequest($admin));

    expect($updated->avatar)->not->toBe($old);
    $disk->assertMissing($old);
    $disk->assertExists($updated->avatar);
});

test('update audita activar y desactivar, y no audita si el estado no cambia', function () {
    $company = Company::factory()->create();
    $admin = userServiceMember($company, UserRole::Admin);
    $user = userServiceMember($company, UserRole::Sales);
    $service = app(UserService::class);
    $audits = fn () => AuditLog::query()->where('user_id', $user->id)->orderBy('id')->get();

    $service->update($admin, $user, ['status' => UserStatus::Inactive->value], null, userServiceRequest($admin));
    $service->update($admin, $user->fresh(), ['status' => UserStatus::Active->value], null, userServiceRequest($admin));
    $service->update($admin, $user->fresh(), ['status' => UserStatus::Active->value], null, userServiceRequest($admin));

    expect($audits()->pluck('action')->all())->toBe([AuditAction::Deactivated->value, AuditAction::Activated->value])
        ->and($audits()->pluck('actor_id')->unique()->all())->toBe([$admin->id]);
});

test('deactivate desactiva al usuario y lo audita', function () {
    $company = Company::factory()->create();
    $admin = userServiceMember($company, UserRole::Admin);
    $user = userServiceMember($company, UserRole::Sales);

    app(UserService::class)->deactivate($admin, $user, userServiceRequest($admin));

    expect($user->fresh()->status)->toBe(UserStatus::Inactive)
        ->and(AuditLog::query()->where('user_id', $user->id)->value('action'))->toBe(AuditAction::Deactivated->value);
});

test('deactivate de la propia cuenta lanza CannotDeactivateSelfException sin cambios', function () {
    $company = Company::factory()->create();
    $admin = userServiceMember($company, UserRole::Admin);
    userServiceMember($company, UserRole::Admin);

    expect(fn () => app(UserService::class)->deactivate($admin, $admin, userServiceRequest($admin)))
        ->toThrow(CannotDeactivateSelfException::class, 'No puedes desactivar tu propia cuenta.');

    expect($admin->fresh()->status)->toBe(UserStatus::Active)
        ->and(AuditLog::query()->where('user_id', $admin->id)->count())->toBe(0);
});
