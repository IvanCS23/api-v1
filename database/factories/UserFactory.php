<?php

namespace Database\Factories;

use App\Enums\UserRole;
use App\Enums\UserStatus;
use App\Models\Company;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

/**
 * @extends Factory<User>
 */
class UserFactory extends Factory
{
    /**
     * The current password being used by the factory.
     */
    protected static ?string $password;

    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'company_id' => Company::factory(),
            'name' => fake()->name(),
            'email' => fake()->unique()->safeEmail(),
            'email_verified_at' => now(),
            'password' => static::$password ??= Hash::make('password'),
            'remember_token' => Str::random(10),
            // Igual que el default de la columna; explícito para que el
            // usuario en memoria (actingAs) pase el middleware `active`.
            'status' => UserStatus::Active,
            // Sin rol explícito el modelo en memoria queda con role null y
            // RoleService lo trata como sin permisos. Employee (y no 'admin',
            // el default de la columna): tiene los permisos operativos, pero
            // no está en ninguna regla de rol de las policies (PAC, pagos,
            // datos fiscales, usuarios), igual que el null de antes. Las
            // pruebas que necesitan un Admin lo piden explícitamente.
            'role' => UserRole::Employee,
            'two_factor_secret' => null,
            'two_factor_recovery_codes' => null,
            'two_factor_confirmed_at' => null,
        ];
    }

    /**
     * Usuario con rol Client: solo consulta (`*.view`, ver RoleService).
     */
    public function client(): static
    {
        return $this->state(fn (array $attributes) => [
            'role' => UserRole::Client,
        ]);
    }

    /**
     * Indicate that the model's email address should be unverified.
     */
    public function unverified(): static
    {
        return $this->state(fn (array $attributes) => [
            'email_verified_at' => null,
        ]);
    }

    /**
     * Indicate that the model has two-factor authentication configured.
     */
    public function withTwoFactor(): static
    {
        return $this->state(fn (array $attributes) => [
            'two_factor_secret' => encrypt('secret'),
            'two_factor_recovery_codes' => encrypt(json_encode(['recovery-code-1'])),
            'two_factor_confirmed_at' => now(),
        ]);
    }
}
