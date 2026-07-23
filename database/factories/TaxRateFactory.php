<?php

namespace Database\Factories;

use App\Enums\TaxFactorType;
use App\Enums\TaxType;
use App\Models\TaxRate;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<TaxRate>
 */
class TaxRateFactory extends Factory
{
    protected $model = TaxRate::class;

    public function definition(): array
    {
        return [
            'code' => '002',
            'name' => fake()->words(2, true),
            'rate' => 0.16,
            'tax_type' => TaxType::Traslado,
            'factor_type' => TaxFactorType::Tasa,
            'active' => true,
        ];
    }
}
