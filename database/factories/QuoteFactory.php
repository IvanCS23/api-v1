<?php

namespace Database\Factories;

use App\Enums\QuoteStatus;
use App\Models\Client;
use App\Models\Company;
use App\Models\Quote;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Quote>
 */
class QuoteFactory extends Factory
{
    protected $model = Quote::class;

    /**
     * `client_id` se resuelve en función del `company_id` ya definido
     * (mismo razonamiento que SaleFactory) para que ambos pertenezcan
     * siempre a la misma empresa.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'company_id' => Company::factory(),
            'client_id' => fn (array $attributes) => Client::factory()->create(['company_id' => $attributes['company_id']])->id,
            'folio' => 'COT-'.str_pad((string) fake()->unique()->numberBetween(1, 99999999), 8, '0', STR_PAD_LEFT),
            'status' => QuoteStatus::Draft,
            'subtotal' => 0,
            'discount_total' => 0,
            'tax_total' => 0,
            'total' => 0,
            'currency' => 'MXN',
        ];
    }
}
