<?php

namespace Database\Factories;

use App\Models\Company;
use App\Models\Product;
use App\Models\Quote;
use App\Models\QuoteItem;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<QuoteItem>
 */
class QuoteItemFactory extends Factory
{
    protected $model = QuoteItem::class;

    /**
     * `quote_id`/`product_id` se resuelven a partir del `company_id` ya
     * definido (mismo razonamiento que SaleItemFactory).
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'company_id' => Company::factory(),
            'quote_id' => fn (array $attributes) => Quote::factory()->create(['company_id' => $attributes['company_id']])->id,
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
