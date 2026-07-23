<?php

namespace Database\Factories;

use App\Models\Company;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Company>
 */
class CompanyFactory extends Factory
{
    protected $model = Company::class;

    /**
     * Define the model's default state.
     *
     * Solo se fijan los campos NOT NULL sin default a nivel de columna
     * (legal_name, trade_name, rfc, fiscal_regime). El resto (status,
     * language, currency, theme, fiscal_provider, etc.) queda fuera a
     * propósito para que tomen el default real definido en la migración
     * de companies. `uuid` no se fija aquí: lo autogenera el hook
     * `Company::booted()` al crear el modelo.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'legal_name' => fake()->company(),
            'trade_name' => fake()->company(),
            'rfc' => strtoupper(fake()->unique()->bothify('???######???')),
            'fiscal_regime' => '601',
        ];
    }
}
