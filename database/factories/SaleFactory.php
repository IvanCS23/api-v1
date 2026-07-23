<?php

namespace Database\Factories;

use App\Enums\SaleStatus;
use App\Models\Client;
use App\Models\Company;
use App\Models\Sale;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Sale>
 */
class SaleFactory extends Factory
{
    protected $model = Sale::class;

    /**
     * `client_id` se resuelve en función del `company_id` YA definido
     * arriba (Laravel pasa los atributos resueltos hasta el momento a
     * los closures) — así el cliente por defecto siempre pertenece a la
     * misma empresa que la venta, nunca a una creada por separado.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'company_id' => Company::factory(),
            'client_id' => fn (array $attributes) => Client::factory()->create(['company_id' => $attributes['company_id']])->id,
            'folio' => 'VTA-'.str_pad((string) fake()->unique()->numberBetween(1, 99999999), 8, '0', STR_PAD_LEFT),
            'status' => SaleStatus::Draft,
            'subtotal' => 0,
            'discount_total' => 0,
            'tax_total' => 0,
            'total' => 0,
            'currency' => 'MXN',
        ];
    }
}
