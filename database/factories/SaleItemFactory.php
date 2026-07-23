<?php

namespace Database\Factories;

use App\Models\Company;
use App\Models\Product;
use App\Models\Sale;
use App\Models\SaleItem;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<SaleItem>
 */
class SaleItemFactory extends Factory
{
    protected $model = SaleItem::class;

    /**
     * `sale_id`/`product_id` se resuelven a partir del `company_id` ya
     * definido, para que ambos pertenezcan siempre a la misma empresa
     * que la propia línea (mismo razonamiento que SaleFactory).
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'company_id' => Company::factory(),
            'sale_id' => fn (array $attributes) => Sale::factory()->create(['company_id' => $attributes['company_id']])->id,
            'product_id' => fn (array $attributes) => Product::factory()->create(['company_id' => $attributes['company_id']])->id,
            'description' => fake()->words(3, true),
            'quantity' => 1,
            'unit_price' => 100,
            'discount' => 0,
            'subtotal' => 100,
            'tax_total' => 0,
            'total' => 100,
        ];
    }
}
