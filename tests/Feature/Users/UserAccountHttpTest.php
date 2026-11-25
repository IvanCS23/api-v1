<?php

use App\Enums\UserRole;
use App\Enums\UserStatus;
use App\Models\AuditLog;
use App\Models\Company;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

use function Pest\Laravel\actingAs;

/*
 * Contrato HTTP de /api/users que UserAdministrationTest no cubría tras
 * delegar UserController en UserService: autodesactivación y avatar.
 */
function userAccountHttpMember(Company $company, UserRole $role = UserRole::Admin): User
{
    return User::factory()->create(['company_id' => $company->id, 'role' => $role, 'status' => UserStatus::Active]);
}

/**
 * Archivo de imagen falso sin la extensión GD (fake()->image() la exige).
 * Pasa las reglas `image` y `mimes:png` porque reporta image/png.
 */
function userAccountHttpAvatar(string $name = 'avatar.png'): UploadedFile
{
    return UploadedFile::fake()->create($name, 10, 'image/png');
}

test('DELETE del propio usuario responde 422 y no cambia su estado', function () {
    $company = Company::factory()->create();
    $admin = userAccountHttpMember($company);
    // Un segundo Admin: el 422 viene de la autodesactivación, no del último Admin.
    userAccountHttpMember($company);

    actingAs($admin, 'api')->deleteJson("/api/users/{$admin->id}")
        ->assertStatus(422)
        ->assertExactJson(['message' => 'No puedes desactivar tu propia cuenta.']);

    expect($admin->fresh()->status)->toBe(UserStatus::Active)
        ->and(AuditLog::query()->where('user_id', $admin->id)->count())->toBe(0);
});

test('POST con avatar lo guarda en el disco public', function () {
    $disk = Storage::fake('public');
    $company = Company::factory()->create();
    $admin = userAccountHttpMember($company);

    $response = actingAs($admin, 'api')->post('/api/users', [
        'name' => 'Usuario con avatar',
        'email' => 'con-avatar@example.com',
        'password' => 'contraseña-segura-1',
        'password_confirmation' => 'contraseña-segura-1',
        'role' => UserRole::Sales->value,
        'avatar' => userAccountHttpAvatar(),
    ], ['Accept' => 'application/json']);

    $response->assertCreated();
    $user = User::query()->findOrFail($response->json('user.id'));

    expect($user->avatar)->toStartWith('avatars/')
        ->and($response->json('user.avatar'))->toBe($disk->url($user->avatar));
    $disk->assertExists($user->avatar);
});

test('PUT con avatar guarda el nuevo y borra el anterior', function () {
    $disk = Storage::fake('public');
    $company = Company::factory()->create();
    $admin = userAccountHttpMember($company);
    $user = userAccountHttpMember($company, UserRole::Sales);
    $old = userAccountHttpAvatar('viejo.png')->store('avatars', 'public');
    $user->forceFill(['avatar' => $old])->save();

    actingAs($admin, 'api')->put("/api/users/{$user->id}", [
        'avatar' => userAccountHttpAvatar('nuevo.png'),
    ], ['Accept' => 'application/json'])->assertOk();

    $new = $user->fresh()->avatar;

    expect($new)->not->toBe($old)
        ->and($new)->toStartWith('avatars/');
    $disk->assertMissing($old);
    $disk->assertExists($new);
});
